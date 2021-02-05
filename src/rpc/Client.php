<?php

namespace teamones\rpc;

use teamones\breaker\Breaker;

class Client
{

}

$client = stream_socket_client('tcp://127.0.0.1:8888');
$request = [
    'class'   => 'User',
    'method'  => 'get',
    'args'    => [100], // 100 是 $uid
];
fwrite($client, json_encode($request)."\n"); // text协议末尾有个换行符"\n"
$result = fgets($client, 10240000);
$result = json_decode($result, true);
var_export($result);