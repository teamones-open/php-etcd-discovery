<?php

// config/process.php
return [
    // ... 其它进程配置省略

    // etcd_discovery 为进程名称
    'etcd_discovery' => [
        'handler' => teamones\process\Etcd::class
    ],
    'rpc' => [
        'handler' => teamones\process\JsonRpc::class,
        'listen' => 'text://0.0.0.0:8083', // 这里用了text协议，也可以用frame或其它协议
        'count' => 2, // 可以设置多进程
    ]
];

// config/etcd.php
return [
    'discovery' => [
        'etcd_host' => '10.168.30.25:2379',
        'server_name' => 'teamones_saas',
        'server_uuid' => \Webpatser\Uuid\Uuid::generate()->string,
        'http_server_port' => 8080,
        'rpc_server_port' => 8083,
        'service_namespace' => "\\common\\service\\",
        'discovery_name' => ['teamones_im', 'teamones_log'],
        'log' => '',
        'cache' => ''
    ]
];


// config/redis.php
return [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
];