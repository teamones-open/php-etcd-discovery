<?php

namespace teamones\curl;

use teamones\breaker\Breaker;
use teamones\etcd\Discovery;

class Client extends \teamones\http\Client
{
    /**
     * 设置指定服务地址
     * @param string $serverName
     * @return $this
     */
    public function setServerHost($serverName = '')
    {
        $resData = Discovery::instance()->getServerConfigByName($serverName);
        if (!empty($resData) && !empty($resData['server_host'])) {
            // 服务存在
            $this->_host = (string)$resData['server_host'];
            return $this;
        } else {
            throw new \RuntimeException($serverName . ' server not exit', -4000000);
        }
    }

    /**
     * 组装请求, 总超时30s，连接超时500ms
     * @return array|\Yurun\Util\YurunHttp\Http\Response
     */
    public function request()
    {
        $url = $this->generateUrl();

        $serviceKey = md5($url . "_" . $this->_method);
        if (!Breaker::isAvailable($serviceKey)) {
            // 服务熔断不可用
            throw new \RuntimeException('Circuit is not available!', -500500);
        }

        switch ($this->_method) {
            case 'POST':
                $response = self::instance()->timeout(30000, 500)
                    ->headers($this->_headers)
                    ->post($url, $this->_body, 'json');
                break;
            case 'GET':
                $response = self::instance()->timeout(30000, 500)
                    ->headers($this->_headers)
                    ->get($url, $this->_body);
                break;
            default:
                $response = [];
                break;
        }

        if ($response instanceof \Yurun\Util\YurunHttp\Http\Response) {
            if ((int)$response->httpCode() !== 200) {
                // 启用熔断器
                Breaker::failure($serviceKey);

                throw new \RuntimeException($response->getBody(), -4000000);
            } else {
                $body = $response->json(true);
                if (!empty($body['code']) && (int)$body['code'] !== 0) {
                    throw new \RuntimeException($body['msg'], $body['code']);
                }

                // 关闭熔断器
                Breaker::success($serviceKey);

                return $body;
            }
        } else {
            return $response;
        }
    }
}