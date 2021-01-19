<?php

namespace teamones\etcd;

class Registry
{
    /**
     * @var object 对象实例
     */
    protected static $instance = null;

    // 服务UUID
    public static $serverUUID = '';

    // 服务Etcd Host
    public static $serverEtcdHost = '';

    // 注册参数
    protected $serverInfo = [
        'method' => 'register',
        'etcd_host' => '',
        'param' => ''
    ];

    /**
     * Registry constructor.
     * @param string $etcdHost
     * @param string $serverUUID
     */
    public function __construct($etcdHost = '', $serverUUID = '')
    {
        self::$serverEtcdHost = $etcdHost;
        self::$serverUUID = $serverUUID;
    }

    /**
     * 初始化
     * @param string $etcdHost
     * @param string $serverUUID
     * @return object|static
     */
    public static function instance($etcdHost = '', $serverUUID = '')
    {
        if (!empty(self::$instance)) {
            return self::$instance;
        }

        self::$instance = new static($etcdHost, $serverUUID);
        return self::$instance;
    }

    /**
     * 获取服务注册参数配置
     * @param string $serverName
     * @param int $serverPort
     * @return array
     */
    public function generateParam($serverName = '', $serverPort = 8080)
    {
        // etcd 地址
        $this->serverInfo['etcd_host'] = self::$serverEtcdHost;

        $this->serverInfo['param'] = json_encode([
            'uuid' => self::$serverUUID,
            'name' => $serverName,
            'port' => $serverPort
        ]);

        return $this->serverInfo;
    }
}