<?php

namespace teamones;

use teamones\driver\Curl;
use teamones\driver\JsonRpc;

class Request
{
    /**
     * @var array
     */
    protected static $_instance = [];


    /**
     * @param string $name
     * @param string $name
     * @return mixed|\teamones\driver\Curl|\teamones\driver\JsonRpc
     */
    public static function connection($name = 'http')
    {
        if (!isset(static::$_instance[$name])) {
            switch ($name) {
                case 'http':
                    static::$_instance[$name] = new Curl();
                    break;
                case 'rpc':
                    static::$_instance[$name] = new JsonRpc();
                    break;
                default:
                    static::$_instance[$name] = null;
                    break;
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