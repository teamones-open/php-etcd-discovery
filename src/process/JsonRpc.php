<?php

namespace teamones\process;

use Workerman\Connection\TcpConnection;

class JsonRpc
{
    // 服务前缀
    protected static $serviceNamespacePrefix = "\\app\\service\\";

    // 实例化对象
    protected static $instances = [];

    /**
     * JsonRpc constructor.
     */
    public function __construct()
    {
        $config = config('etcd', []);
        if (!isset($config['discovery'])) {
            throw new \RuntimeException("Rpc server config not found");
        }

        if (!empty($config['discovery']['service_namespace'])) {
            // 设置服务前缀
            self::$serviceNamespacePrefix = $config['discovery']['service_namespace'];
        }
    }


    /**
     * 缓存类实例，避免重复初始化
     * @param $serviceClassName
     * @return mixed
     * @throws \Exception
     */
    protected static function getInstances($serviceClassName)
    {
        $class = self::$serviceNamespacePrefix . $serviceClassName;

        if (!isset(self::$instances[$class])) {

            if (class_exists($class)) {
                self::$instances[$class] = new $class;
            } else {
                throw new \Exception("{$class} class does not exist");
            }
        }

        return self::$instances[$class];
    }

    /**
     * 检查参数是否正确
     * @param $request
     * @throws \Exception
     */
    protected static function checkParam($request)
    {
//        $request = [
//            'class'   => 'User',
//            'method'  => 'find',
//            'args'    => [], // 参数
//        ];

        $param = json_decode($request, true);
        if (!isset($param)) {
            throw new \Exception('Parameter is not in JSON format.');
        }

        foreach (['class', 'method', 'args'] as $key) {
            if (!array_key_exists($key, $param)) {
                throw new \Exception("Missing {$key} parameter");
            }
        }
    }

    /**
     * 生成返回数据
     * @param string $message
     * @param array $data
     * @param int $status
     * @return array
     */
    protected static function generateResponse($message = '', $data = [], $status = 0)
    {
        return ["code" => $status, "msg" => $message, "data" => $data];
    }

    /**
     * @param TcpConnection $connection
     * @param $data
     */
    public function onMessage(TcpConnection $connection, $data)
    {
        try {
            // 验证请求参数
            self::checkParam($data);

            // 实例化对象
            $instances = self::getInstances($data['class']);

            // 调用请求方法
            $method = $data['method'];
            $args = $data['args'];

            // 初始化Request对象


            // 执行调用方法
            $resData = call_user_func_array([$instances, $method], $args);

            $response = self::generateResponse('', $resData);
        } catch (\Exception $e) {
            $response = self::generateResponse($e->getMessage(), [], -500000);
        }

        // 返回数据
        $connection->send($response);
    }
}