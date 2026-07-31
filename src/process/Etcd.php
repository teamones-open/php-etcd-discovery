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
    protected const REGISTER_RETRY_MAX_DELAY = 10;
    protected const RECONNECT_MAX_DELAY = 10;

    /**
     * Local Go client websocket address.
     *
     * @var string
     */
    protected static $wsAddr = 'ws://127.0.0.1:8083';

    /**
     * Etcd discovery configuration.
     *
     * @var array
     */
    public static $etcdConfig = [];

    /**
     * @var int|null
     */
    protected $heartbeatTimerId;

    /**
     * @var int|null
     */
    protected $discoveryTimerId;

    /**
     * @var int|null
     */
    protected $registerRetryTimerId;

    /**
     * @var int
     */
    protected $registerRetryDelay = 1;

    /**
     * @var int
     */
    protected $reconnectDelay = 1;

    /**
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
            if (!isset($config['discovery'])) {
                throw new \RuntimeException("Etcd connection discovery not found");
            }
            self::$etcdConfig = $config['discovery'];
        }
    }

    protected function heartbeat(AsyncTcpConnection $connection)
    {
        $this->clearTimer($this->heartbeatTimerId);
        $this->heartbeatTimerId = Timer::add(self::HEARTBEAT_INTERVAL, function () use ($connection) {
            $connection->send("PING");
        });
    }

    /**
     * @throws \Exception
     */
    protected function serviceRegistry(AsyncTcpConnection $connection)
    {
        $registerData = Registry::instance(self::$etcdConfig["etcd_host"], self::$etcdConfig["server_uuid"])
            ->generateParam(self::$etcdConfig['server_name'], self::$etcdConfig['server_port']);
        $connection->send(json_encode($registerData));
    }

    protected function serviceDiscovery(AsyncTcpConnection $connection)
    {
        if ($this->discoveryTimerId !== null) {
            return;
        }

        $this->sendDiscoveryRequest($connection);
        $this->discoveryTimerId = Timer::add(self::DISCOVERY_INTERVAL, function () use ($connection) {
            $this->sendDiscoveryRequest($connection);
        });
    }

    protected function sendDiscoveryRequest(AsyncTcpConnection $connection)
    {
        foreach (self::$etcdConfig["discovery_name"] as $discoveryName) {
            $discoveryData = Discovery::instance()->generateParam($discoveryName);
            $connection->send(json_encode($discoveryData));
        }
    }

    protected function scheduleRegistryRetry(AsyncTcpConnection $connection)
    {
        if ($this->registerRetryTimerId !== null) {
            return;
        }

        $delay = $this->registerRetryDelay;
        $this->registerRetryDelay = min($delay * 2, self::REGISTER_RETRY_MAX_DELAY);
        $this->registerRetryTimerId = Timer::add($delay, function () use ($connection) {
            $this->registerRetryTimerId = null;
            $this->serviceRegistry($connection);
        }, [], false);
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
        $this->stopConnectionTimers();
        $this->registerRetryDelay = 1;
        $this->reconnectDelay = 1;
        $this->heartbeat($connection);
        $this->serviceRegistry($connection);
    }

    protected function wsOnMessage(AsyncTcpConnection $connection, $data)
    {
        if ($data === "PONG") {
            return;
        }

        $dataArr = json_decode($data, true);
        if (!is_array($dataArr)) {
            Log::channel()->error($data);
            return;
        }

        $method = $dataArr['method'] ?? '';
        $code = (int)($dataArr['code'] ?? -1);
        if ($code < 0) {
            Log::channel()->error($dataArr['msg'] ?? 'unknown etcd client error');
            if ($method === 'register') {
                $this->scheduleRegistryRetry($connection);
            }
            return;
        }

        switch ($method) {
            case 'register':
                $this->resetRegistryRetry();
                $this->serviceDiscovery($connection);
                break;
            case 'discovery':
                if (!empty($dataArr['data']['server_name'])) {
                    Discovery::instance()->refreshCache($dataArr['data']['server_name'], $dataArr['data']);
                }
                break;
        }
    }

    /**
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        $connection = new AsyncTcpConnection(self::$wsAddr);

        $connection->onWebSocketConnect = function ($connection) {
            $this->wsOnConnect($connection);
        };

        $connection->onMessage = function ($connection, $data) {
            $this->wsOnMessage($connection, $data);
        };

        $connection->onError = function ($connection, $code, $msg) {
            Log::channel()->error("code:{$code}, msg:{$msg}");
        };

        $connection->onClose = function ($connection) {
            $this->stopConnectionTimers();
            if ($this->stopping) {
                return;
            }

            $delay = $this->reconnectDelay;
            $this->reconnectDelay = min($delay * 2, self::RECONNECT_MAX_DELAY);
            Log::channel()->error("etcd websocket closed, reconnecting in {$delay}s");
            $connection->reConnect($delay);
        };

        $connection->connect();
    }

    public function onWorkerStop()
    {
        $this->stopping = true;
        $this->stopConnectionTimers();
    }
}
