<?php

namespace teamones\process;

use teamones\etcd\Discovery;
use teamones\etcd\Registry;
use teamones\Log;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

class Etcd
{
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

    /** @var AsyncTcpConnection|null */
    protected $connection;

    /** @var int|null */
    protected $heartbeatTimerId;

    /** @var int|null */
    protected $discoveryTimerId;

    /** @var int|null */
    protected $registerRetryTimerId;

    /** @var int|null */
    protected $registerAckTimerId;

    /** @var int|null */
    protected $connectTimerId;

    /** @var int|null */
    protected $reconnectTimerId;

    /** @var int|null */
    protected $goClientTimerId;

    /** @var int */
    protected $registerRetryDelay = 1;

    /** @var int */
    protected $reconnectDelay = 1;

    /** @var float */
    protected $heartbeatExpectedAt = 0.0;

    /** @var float */
    protected $lastServerMessageAt = 0.0;

    /** @var bool */
    protected $heartbeatDriftGraceUsed = false;

    /** @var bool */
    protected $registerAckDriftGraceUsed = false;

    /** @var bool */
    protected $connectDriftGraceUsed = false;

    /** @var bool */
    protected $registerPending = false;

    /** @var bool */
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
     * @throws \Exception
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

    protected function addTimer($interval, $callback, $persistent = true)
    {
        $timerId = Timer::add($interval, $callback, [], $persistent);
        return $timerId === false ? null : $timerId;
    }

    protected function now()
    {
        return microtime(true);
    }

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
     * @throws \Exception
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
