<?php

namespace teamones\http;

use Yurun\Util\HttpRequest;

class Base
{
    /**
     * @var \Yurun\Util\HttpRequest
     */
    protected static $_instance = null;

    // 服务地址
    protected $_host = '';

    // 路由地址
    protected $_route = '';

    // 请求方法
    protected $_method = '';

    // Post请求默认header头
    protected $_headers = [];

    // 设置body参数
    protected $_body = null;

    /**
     * @return HttpRequest
     */
    public static function instance()
    {
        if (empty(self::$_instance)) {
            self::$_instance = new HttpRequest;
        }
        return self::$_instance;
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::instance()->{$name}(... $arguments);
    }
}