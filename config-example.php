<?php

return [
    // ... 其它进程配置省略

    // etcd_discovery 为进程名称
    'etcd_discovery' => [
        'handler' => teamones\process\Etcd::class
    ],
];


return [
    'redis' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth' => '',     // 密码，可选参数
            'db' => 0,      // 数据库
            'max_attempts' => 5, // 消费失败后，重试次数
            'retry_seconds' => 5, // 重试间隔，单位秒
        ]
    ],
    'discovery' => [
        'etcd_host' => '10.168.30.25:2379',
        'server_name' => 'teamones_saas',
        'server_uuid' =>  \Webpatser\Uuid\Uuid::generate()->string,
        'server_port' => 8080,
        'discovery_name' => ['teamones_im', 'teamones_log']
    ]
];