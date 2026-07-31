<?php

namespace teamones\driver;

use teamones\breaker\Breaker;
use teamones\etcd\Discovery;

class Curl extends \teamones\http\Client
{
    protected const DEFAULT_REQUEST_TIMEOUT = 30000;
    protected const DEFAULT_CONNECT_TIMEOUT = 500;
    protected const ERROR_BODY_LIMIT = 512;

    /**
     * Set the host returned by service discovery.
     *
     * @param string $serverName
     * @return $this
     */
    public function setServerHost($serverName = '')
    {
        $resData = Discovery::instance()->getServerConfigByName($serverName);
        if (!empty($resData) && !empty($resData['server_host'])) {
            $this->_host = (string)$resData['server_host'];
            return $this;
        }

        throw new \RuntimeException($serverName . ' server not exit', -4000000);
    }

    /**
     * @param string $type
     * @return array|mixed|\Yurun\Util\YurunHttp\Http\Response
     */
    public function request($type = 'sync')
    {
        $url = $this->generateUrl();
        [$requestTimeout, $connectTimeout] = $this->requestTimeouts();

        $serviceKey = md5($url . "_" . $this->_method);
        if (!Breaker::isAvailable($serviceKey)) {
            throw new \RuntimeException('Circuit is not available!', -500500);
        }

        switch ($this->_method) {
            case 'POST':
                $response = self::instance()->timeout($requestTimeout, $connectTimeout)
                    ->headers($this->_headers)
                    ->post($url, $this->_body, 'json');
                break;
            case 'GET':
                $response = self::instance()->timeout($requestTimeout, $connectTimeout)
                    ->headers($this->_headers)
                    ->get($url, $this->_body);
                break;
            default:
                $response = [];
                break;
        }

        if (!$response instanceof \Yurun\Util\YurunHttp\Http\Response) {
            return $response;
        }

        $httpCode = (int)$response->httpCode();
        if ($httpCode !== 200) {
            Breaker::failure($serviceKey);
            throw new \RuntimeException(
                $this->formatHttpError($url, $httpCode, (string)$response->getBody()),
                -4000000
            );
        }

        Breaker::success($serviceKey);
        $body = $response->json(true);
        if (!empty($body['code']) && (int)$body['code'] !== 0) {
            throw new \RuntimeException($body['msg'], $body['code']);
        }

        return $body;
    }

    /**
     * @return int[]
     */
    protected function requestTimeouts()
    {
        $config = config('etcd', []);
        $discoveryConfig = $config['discovery'] ?? [];
        $requestTimeout = max(
            1,
            (int)($discoveryConfig['request_timeout'] ?? self::DEFAULT_REQUEST_TIMEOUT)
        );
        $connectTimeout = max(
            1,
            (int)($discoveryConfig['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT)
        );

        return [$requestTimeout, min($connectTimeout, $requestTimeout)];
    }

    protected function formatHttpError($url, $httpCode, $responseBody)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $message = sprintf(
            'HTTP request failed: method=%s, host=%s, path=%s, status=%d',
            $this->_method,
            $this->_host,
            $path,
            $httpCode
        );
        $responseBody = trim((string)$responseBody);
        if ($responseBody === '') {
            return $message;
        }

        $responseBody = (string)preg_replace('/\s+/', ' ', $responseBody);
        return $message . ', body=' . substr($responseBody, 0, self::ERROR_BODY_LIMIT);
    }
}
