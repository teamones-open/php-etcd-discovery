<?php

namespace teamones\driver;

use teamones\breaker\Breaker;

class JsonRPC extends \teamones\rpc\Client
{
    /**
     * @var null
     */
    protected static $_instance = null;

    /**
     * 组装请求, 总超时3s，返回数据格式 ["code"=>200, "msg"=>"", "data"=>[]]
     * @return array
     */
    public function request()
    {
        $serviceKey = md5("rpc_" . $this->_host . "_" . $this->_route);
        if (!Breaker::isAvailable($serviceKey)) {
            // 服务熔断不可用
            throw new \RuntimeException('Circuit is not available!', -500500);
        }

        try {
            $response = self::instance()
                ->init($this->_host)
                ->block(true)
                ->timeout(3)
                ->get($this->_route, $this->_body);
        } catch (\Exception $e) {

            // 启用熔断器
            Breaker::failure($serviceKey);

            throw new \RuntimeException($e->getMessage(), $e->getCode());
        }

        // 关闭熔断器
        Breaker::success($serviceKey);

        if (!empty($response['code']) && (int)$response['code'] !== 0) {
            throw new \RuntimeException($response['msg'], $response['code']);
        }

        return $response['data'];
    }
}