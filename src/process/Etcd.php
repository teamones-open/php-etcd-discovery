<?php

namespace teamones\process;

use teamones\etcd\Discovery;
use teamones\etcd\Registry;
use teamones\Log;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

class Etcd
{
    // websocket 地址
    protected static $wsAddr = 'ws://127.0.0.1:8083';


    // 服务Etcd Host
    public static $etcdConfig = [];

    /**
     * Etcd constructor.
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

    /**
     * 每隔10秒发送ws心跳维持包
     * @param AsyncTcpConnection $connection
     */
    protected function heartbeat(AsyncTcpConnection $connection)
    {
        // 每隔10秒发送心跳包
        Timer::add(10, function () use ($connection) {
            $connection->send("PING");
        });
    }

    /**
     * 服务注册
     * @param AsyncTcpConnection $connection
     * @throws \Exception
     */
    protected function serviceRegistry(AsyncTcpConnection $connection)
    {
        $registerData = Registry::instance(self::$etcdConfig["etcd_host"], self::$etcdConfig["server_uuid"])
            ->generateParam(self::$etcdConfig['server_name'], self::$etcdConfig['server_port']);
        $connection->send(json_encode($registerData));
    }

    /**
     * 维护服务发现
     * @param AsyncTcpConnection $connection
     */
    protected function serviceDiscovery(AsyncTcpConnection $connection)
    {
        // 每隔1秒维护服务节点状态
        Timer::add(1, function () use ($connection) {
            foreach (self::$etcdConfig["discovery_name"] as $discoveryName) {
                $discoveryData = Discovery::instance()
                    ->generateParam($discoveryName);
                $connection->send(json_encode($discoveryData));
            }
        });
    }

    /**
     * 连接上注册 etcd 服务
     * @param AsyncTcpConnection $connection
     * @throws \Exception
     */
    protected function wsOnConnect(AsyncTcpConnection $connection)
    {
        // 开启心跳包
        $this->heartbeat($connection);

        // 注册服务
        $this->serviceRegistry($connection);
    }

    /**
     * 处理消息
     * @param AsyncTcpConnection $connection
     * @param $data
     */
    protected function wsOnMessage(AsyncTcpConnection $connection, $data)
    {
        if ($data === "PONG") {
            return;
        }

        $dataArr = json_decode($data, true);

        if (is_array($dataArr)) {
            if ($dataArr['code'] >= 0) {
                // 正常返回
                switch ($dataArr['method']) {
                    case 'register':
                        // 注册成功开启服务发现定时任务
                        $this->serviceDiscovery($connection);
                        break;
                    case 'discovery':
                        // 服务发现返回数据写入Cache
                        Discovery::instance()->refreshCache($dataArr['data']['server_name'], $dataArr['data']);
                        break;
                }
            } else {
                // 数据异常写入日志
                Log::channel()->error($dataArr['msg']);
            }

        } else {
            // 数据异常写入日志
            Log::channel()->error($data);
        }
    }

    /**
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        $connection = new AsyncTcpConnection(self::$wsAddr);

        // 给接口发送数据
        $connection->onWebSocketConnect = function ($connection) {
            $this->wsOnConnect($connection);
        };

        // 处理收到信息
        $connection->onMessage = function ($connection, $data) {
            $this->wsOnMessage($connection, $data);
        };

        // 处理错误信息
        $connection->onError = function ($connection, $code, $msg) {
            // 数据异常写入日志
            Log::channel()->error("code:{$code}, msg:{$msg}");
        };

        $connection->connect();
    }


    // stop 事件
    public function onWorkerStop()
    {

    }
}