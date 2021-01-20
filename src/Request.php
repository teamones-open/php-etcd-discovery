<?php

namespace teamones;

use teamones\http\Client;

class Request
{
    /**
     * @var array
     */
    protected static $_instance = [];


    /**
     * @param string $name
     * @param string $name
     * @return mixed|\teamones\http\Client
     */
    public static function connection($name = 'http')
    {
        if (!isset(static::$_instance[$name])) {
            if ($name === 'http') {
                static::$_instance[$name] = new Client();
            } else {
                static::$_instance[$name] = null;
            }
        }
        return static::$_instance[$name];
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::connection('http')->{$name}(... $arguments);
    }
}