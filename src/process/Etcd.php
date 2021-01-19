<?php

namespace teamones\process;

use teamones\etcd\Discovery;
use teamones\etcd\Registry;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Timer;

class Etcd
{
    // websocket 地址
    protected static $wsAddr = 'ws://127.0.0.1:8083';


    // 服务Etcd Host
    public static $etcdConfig = [];

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
     * 后台运行 go etcd ws 服务端
     */
    protected function exec()
    {
        // 拉起 php_etcd_client 客户端并后台运行
        exec("nohup " . __DIR__ . "/../../bin/php_etcd_client >/dev/null 2>&1 &", $output);
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
        // 每隔10秒维护服务节点状态
        Timer::add(10, function () use ($connection) {
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
            if ($dataArr['code'] > 0) {
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
                // 数据异常
                throw new \RuntimeException($dataArr['msg'], -4000000);
            }

        } else {
            // 数据异常
            throw new \RuntimeException($data, -4000000);
        }
    }

    /**
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        // 拉起 go etcd ws 服务端
        $this->exec();

        $connection = new AsyncTcpConnection(self::$wsAddr);

        // 给接口发送数据
        $connection->onConnect = function ($connection) {
            $this->wsOnConnect($connection);
        };

        // 处理收到信息
        $connection->onMessage = function ($connection, $data) {
            $this->wsOnMessage($connection, $data);
        };

        // 处理错误信息
        $connection->onError = function ($connection, $code, $msg) {
            throw new \RuntimeException($msg, -$code);
        };

        $connection->connect();
    }
}