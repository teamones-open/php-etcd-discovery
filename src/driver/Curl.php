<?php

namespace teamones\driver;

use teamones\breaker\Breaker;
use teamones\etcd\Discovery;
use Yurun\Util\HttpRequest;
use Yurun\Util\YurunHttp\Http\Response;

/**
 * 服务发现 HTTP 客户端。
 *
 * 上层 SDK 会复用当前实例，因此每次调用必须隔离 Header/Body。
 * 熔断器只统计网络故障和可重试 HTTP 状态，不将业务异常误判为服务不可用。
 */
class Curl extends \teamones\http\Client
{
    // 超时单位均为毫秒；非法或越界配置会回退到下列默认值。
    protected const DEFAULT_REQUEST_TIMEOUT = 30000;
    protected const DEFAULT_CONNECT_TIMEOUT = 500;
    protected const MAX_REQUEST_TIMEOUT = 300000;
    protected const MAX_CONNECT_TIMEOUT = 60000;
    protected const ERROR_TEXT_LIMIT = 256;

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
     * 执行同步 HTTP 请求并返回 JSON 解码结果。
     *
     * `$type` 仅为兼容旧 SDK 签名而保留。finally 清理本次请求的 Header/Body，
     * 但保留 host/route/method，以兼容历史链式复用方式。
     *
     * @param string $type
     * @return mixed
     */
    public function request($type = 'sync')
    {
        try {
            return $this->executeRequest();
        } finally {
            $this->resetRequestState();
        }
    }

    /**
     * 执行熔断检查、网络请求和响应解析。
     *
     * 只有传输失败、408、429 和 5xx 计入熔断失败；其他 HTTP 响应已经
     * 证明服务可达，JSON 格式或业务 code 异常也不触发熔断。
     *
     * @return mixed
     */
    protected function executeRequest()
    {
        $url = $this->generateUrl();
        $this->_method = strtoupper((string)$this->_method);
        if (!in_array($this->_method, ['GET', 'POST'], true)) {
            throw new \RuntimeException('Unsupported HTTP method: ' . $this->_method, -4000000);
        }

        [$requestTimeout, $connectTimeout] = $this->requestTimeouts();

        // 按 generateUrl() 生成的 host + route URL 与 HTTP Method 隔离熔断状态。
        $serviceKey = md5($url . "_" . $this->_method);
        if (!Breaker::isAvailable($serviceKey)) {
            throw new \RuntimeException('Circuit is not available!', -500500);
        }

        $response = $this->sendHttpRequest($url, $requestTimeout, $connectTimeout);

        if (!$response instanceof Response) {
            Breaker::failure($serviceKey);
            throw new \RuntimeException(sprintf(
                'HTTP transport failed: method=%s, host=%s, path=%s, error=invalid response type',
                $this->_method,
                $this->_host,
                $this->requestPath($url)
            ), -4000000);
        }

        $httpCode = (int)$response->httpCode();
        $transportErrno = method_exists($response, 'getErrno') ? (int)$response->getErrno() : 0;
        // 响应头可能已是 2xx，但读取 Body 仍可能超时，因此正数 transport errno 优先于状态码。
        if ($httpCode <= 0 || $transportErrno > 0) {
            Breaker::failure($serviceKey);
            throw new \RuntimeException($this->formatHttpError($url, $response), -4000000);
        }

        if (!$this->isSuccessfulStatus($httpCode)) {
            if ($this->shouldTripBreaker($httpCode)) {
                Breaker::failure($serviceKey);
            } else {
                // A non-retryable response still proves that the service is reachable.
                Breaker::success($serviceKey);
            }

            throw new \RuntimeException($this->formatHttpError($url, $response), -4000000);
        }

        Breaker::success($serviceKey);
        return $this->parseResponse($url, $response);
    }

    /**
     * 通过 Yurun HTTP 发送请求。
     *
     * Yurun 会将常规网络故障放入 Response status/errno。参数或编程异常
     * 故意不在这里捕获，更不能被记录为下游服务熔断失败。
     *
     * @param string $url
     * @param int $requestTimeout
     * @param int $connectTimeout
     * @return Response|null
     */
    protected function sendHttpRequest($url, $requestTimeout, $connectTimeout)
    {
        $client = $this->createHttpClient()
            ->timeout($requestTimeout, $connectTimeout)
            ->headers($this->_headers);

        switch ($this->_method) {
            case 'POST':
                return $client->post($url, $this->_body, 'json');
            case 'GET':
                return $client->get($url, $this->_body);
            default:
                throw new \RuntimeException('Unsupported HTTP method: ' . $this->_method, -4000000);
        }
    }

    protected function createHttpClient()
    {
        // teamones/http keeps a static HttpRequest instance whose headers accumulate.
        // A fresh client prevents credentials and trace headers leaking across requests.
        return new HttpRequest();
    }

    /**
     * 读取毫秒级超时配置。
     *
     * 非法或越界值回退默认值，并强制连接超时不超过总请求超时。
     *
     * @return array{0:int,1:int}
     */
    protected function requestTimeouts()
    {
        $config = config('etcd', []);
        if (!is_array($config)) {
            $config = [];
        }
        $discoveryConfig = $config['discovery'] ?? [];
        if (!is_array($discoveryConfig)) {
            $discoveryConfig = [];
        }

        $requestTimeout = $this->normalizeTimeout(
            $discoveryConfig['request_timeout'] ?? self::DEFAULT_REQUEST_TIMEOUT,
            self::DEFAULT_REQUEST_TIMEOUT,
            self::MAX_REQUEST_TIMEOUT
        );
        $connectTimeout = $this->normalizeTimeout(
            $discoveryConfig['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT,
            self::DEFAULT_CONNECT_TIMEOUT,
            self::MAX_CONNECT_TIMEOUT
        );

        return [$requestTimeout, min($connectTimeout, $requestTimeout)];
    }

    protected function normalizeTimeout($value, $default, $maximum)
    {
        if (is_int($value)) {
            $timeout = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $timeout = (int)trim($value);
        } else {
            return $default;
        }

        if ($timeout < 1 || $timeout > $maximum) {
            return $default;
        }

        return $timeout;
    }

    protected function isSuccessfulStatus($httpCode)
    {
        return $httpCode >= 200 && $httpCode < 300;
    }

    protected function shouldTripBreaker($httpCode)
    {
        return $httpCode === 0
            || $httpCode === 408
            || $httpCode === 429
            || ($httpCode >= 500 && $httpCode < 600);
    }

    /**
     * 解析已证明 HTTP 可达的 2xx 响应。
     *
     * 空响应按空数组处理；非法 JSON 和非零业务 code 向上抛出，但不改变
     * 前面已记录的“服务可达”熔断结果。
     *
     * @param string $url
     * @param Response $response
     * @return mixed
     */
    protected function parseResponse($url, Response $response)
    {
        $responseBody = (string)$response->getBody();
        if (trim($responseBody) === '') {
            return [];
        }

        $body = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf(
                'Invalid JSON response: method=%s, host=%s, path=%s, status=%d, body_length=%d',
                $this->_method,
                $this->_host,
                $this->requestPath($url),
                (int)$response->httpCode(),
                strlen($responseBody)
            ), -4000000);
        }

        if (is_array($body) && array_key_exists('code', $body) && (int)$body['code'] !== 0) {
            throw new \RuntimeException(
                (string)($body['msg'] ?? 'Remote service returned an error'),
                (int)$body['code']
            );
        }

        return $body;
    }

    /**
     * 构造可观测但不泄露敏感数据的错误文本。
     *
     * 故意不记录响应正文和 URL query，仅保留 Body 长度；transport error 文本会压平空白并截断。
     *
     * @param string $url
     * @param Response $response
     * @return string
     */
    protected function formatHttpError($url, Response $response)
    {
        $message = sprintf(
            'HTTP request failed: method=%s, host=%s, path=%s, status=%d',
            $this->_method,
            $this->_host,
            $this->requestPath($url),
            (int)$response->httpCode()
        );

        $errno = method_exists($response, 'getErrno') ? (int)$response->getErrno() : 0;
        $error = method_exists($response, 'getError') ? (string)$response->getError() : '';
        if ($errno !== 0) {
            $message .= ', errno=' . $errno;
        }
        if ($error !== '') {
            $message .= ', error=' . $this->sanitizeErrorText($error);
        }

        $bodyLength = strlen((string)$response->getBody());
        if ($bodyLength > 0) {
            $message .= ', body_length=' . $bodyLength;
        }

        return $message;
    }

    /**
     * 只提取 URL path 写入日志，避免 query 中的 token 或业务参数泄露。
     *
     * @return string
     */
    protected function requestPath($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    protected function sanitizeErrorText($text)
    {
        $text = (string)preg_replace('/\s+/', ' ', trim((string)$text));
        return substr($text, 0, self::ERROR_TEXT_LIMIT);
    }

    /**
     * 清理被复用 Curl 实例上的单次请求敏感状态。
     *
     * 必须在 finally 中执行；只清 Header/Body，保留 host/route/method 以兼容旧调用方式。
     *
     * @return void
     */
    protected function resetRequestState()
    {
        $this->_headers = [];
        $this->_body = null;
    }
}
