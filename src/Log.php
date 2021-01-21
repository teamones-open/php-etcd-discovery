<?php

namespace teamones;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use teamones\process\Etcd;

class Log
{
    /**
     * @var array
     */
    protected static $_instance = [];

    /**
     * @param string $name
     * @return Logger;
     */
    public static function channel($name = 'default')
    {
        if (empty(static::$_instance[$name])) {
            static::$_instance[$name] = new Logger($name);
            $logPath = Etcd::$etcdConfig['log'] ?? __DIR__ . '/log';
            static::$_instance[$name]->pushHandler(new StreamHandler($logPath, Logger::ERROR));
        };

        return static::$_instance[$name];
    }


    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::channel('default')->{$name}(... $arguments);
    }
}