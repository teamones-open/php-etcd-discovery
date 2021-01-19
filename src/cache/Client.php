<?php

namespace teamones\cache;

use Workerman\Redis\Client as Redis;

class Client
{
    /**
     * @var Redis[]
     */
    protected static $_connections = null;

    /**
     * @param string $name
     * @return \Workerman\Redis\Client
     */
    public static function connection($name = 'redis')
    {
        if (!isset(static::$_connections[$name])) {
            $config = config('etcd', []);
            if (!isset($config[$name])) {
                throw new \RuntimeException("Etcd connection $name not found");
            }
            $host = $config[$name]['host'];
            $options = $config[$name]['options'];
            $client = new Redis($host, $options);

            if (isset($options['auth'])) {
                $client->auth($options['auth']);
            }
            if (isset($options['db'])) {
                $client->select($options['db']);
            }

            static::$_connections[$name] = $client;
        }
        return static::$_connections[$name];
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::connection('redis')->{$name}(... $arguments);
    }
}