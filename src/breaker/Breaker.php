<?php

namespace teamones\breaker;

use teamones\cache\Client;
use LeoCarmo\CircuitBreaker\CircuitBreaker;

class Breaker
{

    protected static $adapterInit = false;

    /**
     * 初始化
     */
    protected static function instance()
    {

        if (self::$adapterInit) {
            return;
        }

        // redis连接句柄
        $redis = Client::connection();

        // 设置当前熔断器命名空间，读取当前服务名称加上随机数
        $config = config('etcd', []);
        if (!isset($config['discovery'])) {
            throw new \RuntimeException("Etcd connection discovery not found");
        }
        $redisNamespace = $config['discovery']['server_name'] . "_" . $config['discovery']['server_uuid'];
        $adapter = new RedisAdapter($redis, $redisNamespace);

        // Set redis adapter for CB
        CircuitBreaker::setAdapter($adapter);

        // Configure settings for CB
        CircuitBreaker::setGlobalSettings([
            'timeWindow' => 20, // 开路时间（秒）
            'failureRateThreshold' => 15, // 开路故障率
            'intervalToHalfOpen' => 10, // 半开时间（秒）重试
        ]);

        self::$adapterInit = true;
    }


    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        self::instance();
        return CircuitBreaker::{$name}(... $arguments);
    }
}