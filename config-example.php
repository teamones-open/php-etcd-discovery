<?php

return [
    // ... 其它进程配置省略

    // etcd_discovery 为进程名称
    'etcd_discovery' => [
        'handler' => teamones\process\Etcd::class
    ],
];


return [
    'discovery' => [
        'etcd_host' => '10.168.30.25:2379',
        'server_name' => 'teamones_saas',
        'server_uuid' =>  \Webpatser\Uuid\Uuid::generate()->string,
        'server_port' => 8080,
        'discovery_name' => ['teamones_im', 'teamones_log'],
        'log' => ''
    ]
];

return [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
];