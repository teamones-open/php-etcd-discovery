# php-etcd-discovery

[![Php Version](https://img.shields.io/badge/php-%3E=7.4-brightgreen.svg)](https://secure.php.net/)
[![Workerman Version](https://img.shields.io/badge/workerman-%3E=4.0.19-brightgreen.svg)](https://github.com/walkor/Workerman)
[![imi License](https://img.shields.io/badge/license-Apache%202.0-brightgreen.svg)](https://github.com/cgpipline/strack/blob/master/LICENSE)


PHP版本，基于Workerman的ETCD服务注册和发现

- 支持http服务
- 支持 text 协议的 rpc 服务（测试）

# 架构

![image](./PHP_ETCD.jpg)

# 安装

```
composer require teamones/etcd-discovery
```

# 配合 webman 框架使用说明

## etcd 配置文件

**.env 增加**
```dotenv
# 当前服务注册到etcd名称
belong_system='project'

# etcd服务地址
etcd_host=10.168.30.25:2379 

# webman 服务端口
host_port=8080
```

**/config/etcd.php配置**

```php
<?php
return [
    'discovery' => [
        'etcd_host' => env("etcd_host", ''),
        'server_name' => env("belong_system", ''),
        'server_uuid' => \Webpatser\Uuid\Uuid::generate()->string,
        'server_port' => env("host_port", 8080),
        'discovery_name' => ['im', 'saas', 'log', 'media'], // 要发现的服务名
        'log' => runtime_path() . '/logs/etcd.log', 
        'cache' => runtime_path() . '/logs'
    ]
];
```

## etcd-go 服务进程 随着 start.php 启动

**在start.php 增加以下代码**

```php
// Worker::$onMasterReload = function () 上面增加

// 拉起etcd服务
if (class_exists('teamones\process\EtcdGoServer')) {
    \teamones\process\EtcdGoServer::exec();

    // Workerman 关闭杀死 etcd服务
    worker::$onMasterStop = function () {
        \teamones\process\EtcdGoServer::kill();
    };
}
```

## 注册 etcd process worker 进程

**在 /config/process.php中增加**

```php
// etcd 对象
    'etcd' => [
        'handler' => teamones\process\Etcd::class,
        'count' => 1
    ],
```

## 基于 http 协议 curl 服务调用

**默认使用http协议**

```php
<?php

use teamones\Request;

$data = Request::connection()
            ->setHeader([
                'X-Userinfo' => request()->getXUserInfo(), // 一般需要传递用户信息
            ])
            ->setServerHost('im') // 请求服务名
            ->setRoute('options/get_business_mode') // 路由地址
            ->setMethod('POST') // 请求方式
            ->request();
```


