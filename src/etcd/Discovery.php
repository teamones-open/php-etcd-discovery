<?php

namespace teamones\etcd;

use teamones\cache\Client;

class Discovery
{
    /**
     * @var object 对象实例
     */
    protected static $instance = null;


    // cache key
    protected static $cacheKey = '';


    // 注册参数
    protected $serverInfo = [
        'method' => 'discovery',
        'etcd_host' => '',
        'param' => ''
    ];

    /**
     * Discovery constructor.
     */
    public function __construct()
    {
        $uuid = Registry::$serverUUID;
        if (!empty($uuid)) {
            self::$cacheKey = "etcd_discovery" . $uuid;
        } else {
            // 从配置获取
            $config = config('etcd', []);
            if (!isset($config['discovery'])) {
                throw new \RuntimeException("Etcd connection discovery not found");
            }
            self::$cacheKey = "etcd_discovery" . $config['discovery']["server_uuid"];
        }
    }

    /**
     * 初始化
     * @param string $etcdHost
     * @return object|static
     */
    public static function instance()
    {
        if (!empty(self::$instance)) {
            return self::$instance;
        }

        self::$instance = new static();
        return self::$instance;
    }

    /**
     * 获取服务发现参数配置
     * @param string $serverName
     * @param int $serverPort
     * @return array
     */
    public function generateParam($serverName = '', $serverPort = "8080")
    {
        // etcd 地址
        $this->serverInfo['etcd_host'] = Registry::$serverEtcdHost;

        $this->serverInfo['param'] = json_encode([
            'uuid' => (string)Registry::$serverUUID,
            'name' => (string)$serverName,
            'port' => (string)$serverPort
        ]);

        return $this->serverInfo;
    }

    /**
     * 把服务地址写入缓存
     * @param $name
     * @param array $discoveryData
     */
    public function refreshCache($name, $discoveryData = [])
    {
        $cache = Client::get(self::$cacheKey);

        if (empty($cache) && !isset($cache)) {
            $cacheArray = [];
        }else{
            $cacheArray = json_decode($cache, true);
        }

        $cacheArray[$name] = $discoveryData;

        Client::set(self::$cacheKey, json_encode($cacheArray), 'EX', 5);
    }

    /**
     * 通过服务名称获取服务配置
     * @param $name
     * @return array
     */
    public function getServerConfigByName($name)
    {
        $cache =$cache = Client::get(self::$cacheKey);

        if (!empty($cache)) {
            $cacheArray = json_decode($cache, true);
            if (array_key_exists($name, $cacheArray)) {
                return $cacheArray[$name];
            }
        }
        return [];
    }
}