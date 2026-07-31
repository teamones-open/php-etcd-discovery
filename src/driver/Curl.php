<?php

namespace teamones\driver;

use teamones\breaker\Breaker;
use teamones\etcd\Discovery;
use Yurun\Util\HttpRequest;
use Yurun\Util\YurunHttp\Http\Response;

class Curl extends \teamones\http\Client
{
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
     * @param string $type
     * @return array|mixed|Response
     */
    public function request($type = 'sync')
    {
        try {
            return $this->executeRequest();
        } finally {
            $this->resetRequestState();
        }
    }

    protected function executeRequest()
    {
        $url = $this->generateUrl();
        $this->_method = strtoupper((string)$this->_method);
        if (!in_array($this->_method, ['GET', 'POST'], true)) {
            throw new \RuntimeException('Unsupported HTTP method: ' . $this->_method, -4000000);
        }

        [$requestTimeout, $connectTimeout] = $this->requestTimeouts();

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
     * @return int[]
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

    protected function resetRequestState()
    {
        $this->_headers = [];
        $this->_body = null;
    }
}
