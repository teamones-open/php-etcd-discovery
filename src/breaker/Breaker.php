<?php

namespace teamones\breaker;

use teamones\cache\Client;
use teamones\process\Etcd;

class Breaker extends \LeoCarmo\CircuitBreaker\CircuitBreaker
{
    /**
     * Breaker constructor.
     */
    public function __construct()
    {
        // redis连接句柄
        $redis = Client::connection();

        // 设置当前熔断器命名空间，读取当前服务名称加上随机数
        $redisNamespace = Etcd::$etcdConfig['server_name'] . "_" . Etcd::$etcdConfig['server_uuid'];
        $adapter = new RedisAdapter($redis, $redisNamespace);

        // Set redis adapter for CB
        self::setAdapter($adapter);
    }
}