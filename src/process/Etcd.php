<?php

namespace teamones\process;

use teamones\etcd\Discovery;
use teamones\etcd\Registry;
use teamones\Log;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

/**
 * PHP 与本地 Go etcd 客户端之间的 WebSocket 状态机。
 *
 * 连接建立后先注册并等待 ACK，只有注册成功才启动周期性服务发现。
 * 连接、ACK、心跳三层 watchdog 共同保证“进程存活但不再响应”时也能自愈。
 */
class Etcd
{
    // 协议调度、watchdog 与指数退避单位均为秒。
    protected const HEARTBEAT_INTERVAL = 10;
    protected const DISCOVERY_INTERVAL = 1;
    protected const REGISTER_ACK_TIMEOUT = 10;
    protected const SERVER_SILENCE_TIMEOUT = 30;
    protected const CONNECT_TIMEOUT = 10;
    protected const GO_CLIENT_CHECK_INTERVAL = 5;
    protected const TIMER_DRIFT_TOLERANCE = 1;
    protected const TIMER_IO_GRACE = 1;
    protected const REGISTER_RETRY_MAX_DELAY = 10;
    protected const RECONNECT_MAX_DELAY = 10;

    /** @var string */
    protected static $wsAddr = 'ws://127.0.0.1:8083';

    /** @var array */
    public static $etcdConfig = [];

    /**
     * 当前唯一有效连接。所有异步回调都必须校验对象身份，防止旧连接回调污染新会话。
     *
     * @var AsyncTcpConnection|null
     */
    protected $connection;

    /** @var int|null */
    protected $heartbeatTimerId;

    /** @var int|null */
    protected $discoveryTimerId;

    /** @var int|null */
    protected $registerRetryTimerId;

    /** @var int|null */
    protected $registerAckTimerId;

    /**
     * TCP 建连 + WebSocket 握手 watchdog；只有 onWebSocketConnect 表示连接完成。
     *
     * @var int|null
     */
    protected $connectTimerId;

    /**
     * 与 Workerman native reconnect 同期触发的 companion Timer，用于从实际重连时刻启动 watchdog。
     * Workerman 内部重连 Timer 仍由 AsyncTcpConnection::reconnect()/cancelReconnect() 管理。
     *
     * @var int|null
     */
    protected $reconnectTimerId;

    /** @var int|null 独立于 WebSocket 会话的本地 Go 进程存活检查 Timer */
    protected $goClientTimerId;

    /** @var int */
    protected $registerRetryDelay = 1;

    /** @var int */
    protected $reconnectDelay = 1;

    /** @var float */
    protected $heartbeatExpectedAt = 0.0;

    /**
     * 最后一次有效 PONG 或合法协议响应时间；无效帧不能续期。
     *
     * @var float
     */
    protected $lastServerMessageAt = 0.0;

    /** @var bool */
    protected $heartbeatDriftGraceUsed = false;

    /** @var bool */
    protected $registerAckDriftGraceUsed = false;

    /** @var bool */
    protected $connectDriftGraceUsed = false;

    /**
     * register 已发出但尚未收到 ACK。成功或失败 ACK 都会清除，只有无 ACK 才重启 Go。
     *
     * @var bool
     */
    protected $registerPending = false;

    /**
     * Worker 停止门闩；必须在关连接前置 true，阻止同步 onClose 再次安排重连。
     *
     * @var bool
     */
    protected $stopping = false;

    /**
     * @throws \Exception
     */
    public function __construct()
    {
        if (empty(self::$etcdConfig)) {
            $config = config('etcd', []);
            if (!is_array($config)
                || !isset($config['discovery'])
                || !is_array($config['discovery'])
            ) {
                throw new \RuntimeException("Etcd connection discovery not found");
            }

            self::$etcdConfig = $config['discovery'];
        }
    }

    /**
     * 启动应用层 PING/PONG 心跳与服务端静默检测。
     *
     * PONG 和任何合法协议响应都证明 Go 仍活跃。超过静默阈值会重启 Go 并断开当前连接。
     * Select 事件循环可能先执行超期 Timer 再读 socket，所以严重漂移时只给一次 I/O 宽限，
     * 避免误杀也避免无限续期。
     *
     * @param AsyncTcpConnection $connection
     * @return bool
     */
    protected function startHeartbeat(AsyncTcpConnection $connection)
    {
        $this->clearTimer($this->heartbeatTimerId);
        $this->heartbeatExpectedAt = $this->now() + self::HEARTBEAT_INTERVAL;
        $this->heartbeatDriftGraceUsed = false;
        $this->heartbeatTimerId = $this->addTimer(self::HEARTBEAT_INTERVAL, function () use ($connection) {
            if (!$this->isCurrentConnection($connection)) {
                return;
            }

            $now = $this->now();
            $expectedAt = $this->heartbeatExpectedAt;
            $this->heartbeatExpectedAt = $now + self::HEARTBEAT_INTERVAL;

            // Select-based event loops run expired timers before pending socket
            // reads. A delayed callback must first give buffered PONG/data a turn.
            if ($expectedAt > 0
                && $now - $expectedAt > self::TIMER_DRIFT_TOLERANCE
                && !$this->heartbeatDriftGraceUsed
            ) {
                $this->heartbeatDriftGraceUsed = true;
                $this->lastServerMessageAt = $now;
                $this->sendMessage($connection, 'PING');
                return;
            }

            if ($this->lastServerMessageAt > 0
                && $now - $this->lastServerMessageAt >= self::SERVER_SILENCE_TIMEOUT
            ) {
                $this->restartGoClientAndClose($connection, 'etcd websocket server stopped responding');
                return;
            }

            $this->sendMessage($connection, 'PING');
        });

        if ($this->heartbeatTimerId === null) {
            $this->criticalTimerFailure($connection, 'heartbeat');
            return false;
        }

        return true;
    }

    /**
     * 发送注册请求并启动 ACK watchdog。
     *
     * discovery 只能由后续成功 ACK 启动，不能在发送注册后提前运行。
     *
     * @param AsyncTcpConnection $connection
     */
    protected function serviceRegistry(AsyncTcpConnection $connection)
    {
        if (!$this->isCurrentConnection($connection)) {
            return;
        }

        $registerData = Registry::instance(self::$etcdConfig['etcd_host'], self::$etcdConfig['server_uuid'])
            ->generateParam(self::$etcdConfig['server_name'], self::$etcdConfig['server_port']);

        $this->registerPending = true;
        if (!$this->sendMessage($connection, json_encode($registerData))) {
            $this->registerPending = false;
            return;
        }

        $this->scheduleRegisterAckTimeout($connection);
    }

    /**
     * 在注册成功后启动唯一的周期性发现 Timer，并立即触发首次发现。
     *
     * @param AsyncTcpConnection $connection
     * @return void
     */
    protected function serviceDiscovery(AsyncTcpConnection $connection)
    {
        if ($this->discoveryTimerId !== null || !$this->isCurrentConnection($connection)) {
            return;
        }

        // Save the timer before the first send so a synchronous close can remove it.
        $this->discoveryTimerId = $this->addTimer(self::DISCOVERY_INTERVAL, function () use ($connection) {
            if ($this->isCurrentConnection($connection)) {
                $this->sendDiscoveryRequest($connection);
            }
        });
        if ($this->discoveryTimerId === null) {
            $this->criticalTimerFailure($connection, 'discovery');
            return;
        }

        $this->sendDiscoveryRequest($connection);
    }

    protected function sendDiscoveryRequest(AsyncTcpConnection $connection)
    {
        foreach (self::$etcdConfig['discovery_name'] as $discoveryName) {
            $discoveryData = Discovery::instance()->generateParam($discoveryName);
            if (!$this->sendMessage($connection, json_encode($discoveryData))) {
                return;
            }
        }
    }

    /**
     * 为当前 register attempt 重置 ACK 超时状态。
     *
     * @param AsyncTcpConnection $connection
     * @return void
     */
    protected function scheduleRegisterAckTimeout(AsyncTcpConnection $connection)
    {
        $this->clearTimer($this->registerAckTimerId);
        $this->registerAckDriftGraceUsed = false;
        $this->armRegisterAckTimeout($connection, self::REGISTER_ACK_TIMEOUT);
    }

    protected function armRegisterAckTimeout(AsyncTcpConnection $connection, $delay)
    {
        $deadline = $this->now() + $delay;
        $this->registerAckTimerId = $this->addTimer($delay, function () use ($connection, $deadline) {
            $this->registerAckTimerId = null;
            if (!$this->isCurrentConnection($connection) || !$this->registerPending) {
                return;
            }

            if ($this->now() - $deadline > self::TIMER_DRIFT_TOLERANCE
                && !$this->registerAckDriftGraceUsed
            ) {
                $this->registerAckDriftGraceUsed = true;
                $this->armRegisterAckTimeout($connection, self::TIMER_IO_GRACE);
                return;
            }

            $this->registerPending = false;
            $this->restartGoClientAndClose($connection, 'etcd register acknowledgement timed out');
        }, false);

        if ($this->registerAckTimerId === null) {
            $this->registerPending = false;
            $this->criticalTimerFailure($connection, 'register acknowledgement');
        }
    }

    protected function scheduleRegistryRetry(AsyncTcpConnection $connection)
    {
        if ($this->registerRetryTimerId !== null || !$this->isCurrentConnection($connection)) {
            return;
        }

        $delay = $this->registerRetryDelay;
        $this->registerRetryDelay = min($delay * 2, self::REGISTER_RETRY_MAX_DELAY);
        $this->registerRetryTimerId = $this->addTimer($delay, function () use ($connection) {
            $this->registerRetryTimerId = null;
            if (!$this->isCurrentConnection($connection)) {
                return;
            }

            try {
                $this->serviceRegistry($connection);
            } catch (\Throwable $e) {
                $this->connectionCallbackFailure($connection, 'register retry', $e);
            }
        }, false);

        if ($this->registerRetryTimerId === null) {
            $this->criticalTimerFailure($connection, 'register retry');
        }
    }

    /**
     * 从实际 connect attempt 开始计算建连超时，不把 reconnect delay 计入。
     *
     * Timer 自身严重漂移时只重置一次新窗口；宽限后仍未完成 WebSocket 握手就执行恢复。
     *
     * @param AsyncTcpConnection $connection
     * @param bool $resetDriftGrace
     * @return void
     */
    protected function scheduleConnectTimeout(
        AsyncTcpConnection $connection,
        $resetDriftGrace = true
    )
    {
        $this->clearTimer($this->connectTimerId);
        $this->connectDriftGraceUsed = $resetDriftGrace ? false : $this->connectDriftGraceUsed;
        $deadline = $this->now() + self::CONNECT_TIMEOUT;
        $this->connectTimerId = $this->addTimer(self::CONNECT_TIMEOUT, function () use ($connection, $deadline) {
            $this->connectTimerId = null;
            if (!$this->isCurrentConnection($connection)) {
                return;
            }

            // If this timer itself ran late, socket readiness has not had a
            // chance to run yet. Start a fresh timeout window from this tick.
            if ($this->now() - $deadline > self::TIMER_DRIFT_TOLERANCE
                && !$this->connectDriftGraceUsed
            ) {
                $this->connectDriftGraceUsed = true;
                $this->scheduleConnectTimeout($connection, false);
                return;
            }

            $this->restartGoClientAndClose($connection, 'etcd websocket connect timed out');
        }, false);

        if ($this->connectTimerId === null) {
            $this->criticalTimerFailure($connection, 'connect watchdog');
        }
    }

    /**
     * 为 Workerman native reconnect 配置同期 companion Timer。
     *
     * 调用方必须先在 onClose 内调用 native reconnect($delay)，使状态在回调返回前
     * 回到 INITIAL，避免 Workerman destroy() 清空回调。两个 Timer 同期竞态时：
     * custom 先执行则仅在 INITIAL 状态调用 reconnect(0)，native 先执行则在
     * CONNECTING/ESTABLISHED 状态下只启动 watchdog，绝不再发起第二次连接。
     *
     * @param AsyncTcpConnection $connection
     * @param int|float $delay
     * @return void
     */
    protected function scheduleReconnect(AsyncTcpConnection $connection, $delay)
    {
        $this->clearTimer($this->reconnectTimerId);
        $this->reconnectTimerId = $this->addTimer($delay, function () use ($connection) {
            $this->reconnectTimerId = null;
            if (!$this->isCurrentConnection($connection)) {
                return;
            }

            // Workerman's own reconnect timer normally starts first. If this
            // timer wins the same-tick race, start it now. Calling reconnect()
            // in onClose is still required so Workerman preserves callbacks.
            if ($connection->getStatus() === AsyncTcpConnection::STATUS_INITIAL) {
                $connection->reconnect(0);
            }

            if (!$this->isCurrentConnection($connection)) {
                return;
            }

            $status = $connection->getStatus();
            if ($status === AsyncTcpConnection::STATUS_CONNECTING
                || $status === AsyncTcpConnection::STATUS_ESTABLISHED
            ) {
                $this->scheduleConnectTimeout($connection);
            }
        }, false);

        if ($this->reconnectTimerId === null) {
            $this->criticalTimerFailure($connection, 'reconnect');
        }
    }

    /**
     * 启动独立于 WebSocket 会话的 Go 进程存活监控。
     *
     * 这里只校验 OS 进程身份；“进程存活但业务无响应”由注册 ACK 和心跳 watchdog 处理。
     *
     * @return void
     */
    protected function startGoClientMonitor()
    {
        $this->ensureGoClientRunning();
        $this->clearTimer($this->goClientTimerId);
        $this->goClientTimerId = $this->addTimer(self::GO_CLIENT_CHECK_INTERVAL, function () {
            if (!$this->stopping) {
                $this->ensureGoClientRunning();
            }
        });
    }

    protected function ensureGoClientRunning()
    {
        try {
            if (!EtcdGoServer::exec()) {
                $this->logError('unable to start php_etcd_client');
            }
        } catch (\Throwable $e) {
            $this->logError('php_etcd_client monitor failed: ' . $e->getMessage());
        }
    }

    protected function restartGoClientAndClose(AsyncTcpConnection $connection, $reason)
    {
        if (!$this->isCurrentConnection($connection)) {
            return;
        }

        $this->logError($reason . ', restarting php_etcd_client');
        try {
            if (!EtcdGoServer::restart()) {
                $this->logError('unable to restart php_etcd_client');
            }
        } catch (\Throwable $e) {
            $this->logError('php_etcd_client restart failed: ' . $e->getMessage());
        }

        $this->closeConnection($connection, true);
    }

    protected function connectionCallbackFailure(
        AsyncTcpConnection $connection,
        $operation,
        \Throwable $exception
    ) {
        $this->logError("etcd websocket {$operation} failed: " . $exception->getMessage());
        $this->closeConnection($connection);
    }

    /**
     * 处理不可恢复的 Timer 创建失败。
     *
     * 缺少 watchdog 时继续运行比立即退出更危险，因此停止重连后故意抛异常，
     * 交给 Workerman master 重启当前不安全 Worker。
     *
     * @return void
     */
    protected function criticalTimerFailure(AsyncTcpConnection $connection, $timerName)
    {
        $message = "unable to create etcd {$timerName} timer";
        $this->logError($message);

        // Workerman cannot maintain this connection without its watchdog.
        // Stop reconnect recursion and let the master restart this worker.
        $this->stopping = true;
        $this->stopConnectionTimers();
        $connection->cancelReconnect();
        if ($connection->getStatus() !== AsyncTcpConnection::STATUS_CLOSING
            && $connection->getStatus() !== AsyncTcpConnection::STATUS_CLOSED
        ) {
            $connection->close();
        }

        throw new \RuntimeException($message);
    }

    protected function sendMessage(AsyncTcpConnection $connection, $message)
    {
        if (!$this->isCurrentConnection($connection)) {
            return false;
        }

        try {
            $result = $connection->send($message);
        } catch (\Throwable $e) {
            $this->logError('etcd websocket send failed: ' . $e->getMessage());
            $this->closeConnection($connection);
            return false;
        }

        if ($result === false) {
            $this->logError('etcd websocket send buffer is unavailable');
            $this->closeConnection($connection);
            return false;
        }

        return true;
    }

    protected function closeConnection(AsyncTcpConnection $connection, $cancelReconnect = false)
    {
        $status = $connection->getStatus();
        if ($status === AsyncTcpConnection::STATUS_CLOSING
            || $status === AsyncTcpConnection::STATUS_CLOSED
        ) {
            return;
        }

        if ($status === AsyncTcpConnection::STATUS_INITIAL) {
            if (!$cancelReconnect) {
                return;
            }
            $connection->cancelReconnect();
        }
        $connection->close();
    }

    protected function isCurrentConnection(AsyncTcpConnection $connection)
    {
        return !$this->stopping && $this->connection === $connection;
    }

    protected function resetRegistryRetry()
    {
        $this->clearTimer($this->registerRetryTimerId);
        $this->registerRetryDelay = 1;
    }

    /**
     * 清理当前 WebSocket 会话的所有 package Timer。
     *
     * 不清理 Go supervisor Timer，也不管理 Workerman 内部 native reconnect Timer；
     * native Timer 必须由 cancelReconnect() 单独取消。
     *
     * @return void
     */
    protected function stopConnectionTimers()
    {
        $this->clearTimer($this->heartbeatTimerId);
        $this->clearTimer($this->discoveryTimerId);
        $this->clearTimer($this->registerRetryTimerId);
        $this->clearTimer($this->registerAckTimerId);
        $this->clearTimer($this->connectTimerId);
        $this->clearTimer($this->reconnectTimerId);
        $this->heartbeatExpectedAt = 0.0;
    }

    /**
     * 统一将 Workerman Timer::add() 的 false 失败值转换为 null，便于状态机检查。
     *
     * @return int|null
     */
    protected function addTimer($interval, $callback, $persistent = true)
    {
        $timerId = Timer::add($interval, $callback, [], $persistent);
        return $timerId === false ? null : $timerId;
    }

    protected function now()
    {
        return microtime(true);
    }

    /**
     * best-effort 记录恢复路径错误。
     *
     * 日志客户端未配置或 handler 抛异常时降级到 error_log，不允许日志故障阻断自愈。
     *
     * @return void
     */
    protected function logError($message)
    {
        $message = (string)$message;

        try {
            $logger = Log::channel();
            if (is_object($logger) && is_callable([$logger, 'error'])) {
                $logger->error($message);
                return;
            }
        } catch (\Throwable $loggingException) {
            // Recovery paths must not fail because the optional logger failed.
        }

        error_log($message);
    }

    /**
     * @param int|null $timerId
     */
    protected function clearTimer(&$timerId)
    {
        if ($timerId === null) {
            return;
        }

        Timer::del($timerId);
        $timerId = null;
    }

    /**
     * @throws \Exception
     */
    protected function wsOnConnect(AsyncTcpConnection $connection)
    {
        if ($this->connection !== $connection || $this->stopping) {
            $connection->close();
            return;
        }

        $this->stopConnectionTimers();
        $this->lastServerMessageAt = $this->now();
        $this->registerPending = false;
        if (!$this->startHeartbeat($connection)) {
            return;
        }

        try {
            $this->serviceRegistry($connection);
        } catch (\Throwable $e) {
            $this->connectionCallbackFailure($connection, 'register', $e);
        }
    }

    /**
     * 处理 Go 客户端协议响应。
     *
     * 只有 PONG 或 method/code 合法的 register/discovery 响应才能更新活跃时间；
     * 无效帧既不刷新 watchdog，也不修改注册状态。
     *
     * @return void
     */
    protected function wsOnMessage(AsyncTcpConnection $connection, $data)
    {
        if (!$this->isCurrentConnection($connection)) {
            return;
        }

        if ($data === 'PONG') {
            $this->lastServerMessageAt = $this->now();
            $this->heartbeatDriftGraceUsed = false;
            return;
        }

        $dataArr = json_decode($data, true);
        if (!is_array($dataArr)) {
            $this->logError('invalid etcd websocket response');
            return;
        }

        $method = $dataArr['method'] ?? '';
        if (!in_array($method, ['register', 'discovery'], true)
            || !array_key_exists('code', $dataArr)
            || !is_numeric($dataArr['code'])
        ) {
            $this->logError('invalid etcd websocket protocol response');
            return;
        }

        $this->lastServerMessageAt = $this->now();
        $this->heartbeatDriftGraceUsed = false;
        $code = (int)($dataArr['code'] ?? -1);
        if ($method === 'register') {
            $this->clearTimer($this->registerAckTimerId);
            $this->registerPending = false;
        }

        if ($code < 0) {
            $this->logError($dataArr['msg'] ?? 'unknown etcd client error');
            if ($method === 'register') {
                $this->scheduleRegistryRetry($connection);
            }
            return;
        }

        switch ($method) {
            case 'register':
                $this->resetRegistryRetry();
                $this->reconnectDelay = 1;
                $this->serviceDiscovery($connection);
                break;
            case 'discovery':
                if (!empty($dataArr['data']['server_name'])) {
                    try {
                        Discovery::instance()->refreshCache($dataArr['data']['server_name'], $dataArr['data']);
                    } catch (\Throwable $e) {
                        $this->logError('etcd discovery cache refresh failed: ' . $e->getMessage());
                    }
                }
                break;
        }
    }

    /**
     * 启动 Go 进程监控并创建唯一 WebSocket 连接。
     *
     * 所有回调共享同一 connection 身份校验，断线后使用 native reconnect
     * 保留回调，再由 companion Timer 在实际重连时启动建连 watchdog。
     *
     * @return void
     */
    public function onWorkerStart()
    {
        $this->stopping = false;
        $this->startGoClientMonitor();

        $connection = new AsyncTcpConnection(self::$wsAddr);
        $this->connection = $connection;

        $connection->onWebSocketConnect = function ($connection) {
            $this->wsOnConnect($connection);
        };

        $connection->onMessage = function ($connection, $data) {
            $this->wsOnMessage($connection, $data);
        };

        $connection->onError = function ($connection, $code, $msg) {
            if ($this->connection === $connection) {
                $this->logError("code:{$code}, msg:{$msg}");
            }
        };

        $connection->onClose = function ($connection) {
            if ($this->connection !== $connection) {
                return;
            }

            $this->stopConnectionTimers();
            $this->registerPending = false;
            if ($this->stopping) {
                return;
            }

            $this->ensureGoClientRunning();
            $delay = $this->reconnectDelay;
            $this->reconnectDelay = min($delay * 2, self::RECONNECT_MAX_DELAY);
            $this->logError("etcd websocket closed, reconnecting in {$delay}s");
            // This sets status back to INITIAL before onClose returns. Without
            // it Workerman destroys the callbacks after the close callback.
            $connection->reconnect($delay);
            // Separately arm our watchdog from the actual reconnect tick.
            $this->scheduleReconnect($connection, $delay);
        };

        $connection->connect();
        if ($this->isCurrentConnection($connection)
            && $connection->getStatus() === AsyncTcpConnection::STATUS_CONNECTING
        ) {
            $this->scheduleConnectTimeout($connection);
        }
    }

    /**
     * 停止当前 Worker 的连接状态机。
     *
     * 顺序不可改为：stopping=true -> 清 package Timer -> cancel native reconnect -> close。
     * 先将 stopping 置为 true，才能保证 close 同步触发 onClose 时不会复活连接。
     *
     * @return void
     */
    public function onWorkerStop()
    {
        $this->stopping = true;
        $this->stopConnectionTimers();
        $this->clearTimer($this->goClientTimerId);

        $connection = $this->connection;
        if ($connection !== null) {
            $connection->cancelReconnect();
            $connection->close();
        }
        $this->connection = null;
    }
}
