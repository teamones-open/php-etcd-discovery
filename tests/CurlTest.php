<?php

namespace teamones\breaker {
    class Breaker
    {
        public static $events = [];
        public static $available = true;

        public static function isAvailable($service)
        {
            return self::$available;
        }

        public static function failure($service)
        {
            self::$events[] = 'failure';
        }

        public static function success($service)
        {
            self::$events[] = 'success';
        }

        public static function reset()
        {
            self::$events = [];
            self::$available = true;
        }
    }
}

namespace teamones\etcd {
    class Discovery
    {
        public static function instance()
        {
            return new self();
        }

        public function getServerConfigByName($name)
        {
            return ['server_host' => 'http://service.local'];
        }
    }
}

namespace teamones\http {
    class Client
    {
        protected $_host = '';
        protected $_route = '';
        protected $_method = '';
        protected $_headers = [];
        protected $_body;

        protected function generateUrl()
        {
            if ($this->_host === '') {
                throw new \RuntimeException('missing host');
            }
            return $this->_route === '' ? $this->_host : $this->_host . '/' . $this->_route;
        }

        public function setHost($host)
        {
            $this->_host = $host;
            return $this;
        }

        public function setRoute($route)
        {
            $this->_route = $route;
            return $this;
        }

        public function setMethod($method)
        {
            $this->_method = ucwords($method);
            return $this;
        }

        public function setHeader($headers)
        {
            $this->_headers = $this->_method === 'POST'
                ? array_merge($this->_headers, $headers)
                : $headers;
            return $this;
        }

        public function setBody($body)
        {
            $this->_body = $body;
            return $this;
        }
    }
}

namespace Yurun\Util {
    class HttpRequest
    {
    }
}

namespace Yurun\Util\YurunHttp\Http {
    class Response
    {
        protected $status;
        protected $body;
        protected $errno;
        protected $error;

        public function __construct($status, $body = '', $errno = 0, $error = '')
        {
            $this->status = $status;
            $this->body = $body;
            $this->errno = $errno;
            $this->error = $error;
        }

        public function httpCode()
        {
            return $this->status;
        }

        public function getBody()
        {
            return $this->body;
        }

        public function getErrno()
        {
            return $this->errno;
        }

        public function getError()
        {
            return $this->error;
        }
    }
}

namespace teamones\driver {
    function config($name, $default = [])
    {
        return $GLOBALS['curl_test_config'] ?? $default;
    }
}

namespace {
    use teamones\breaker\Breaker;
    use teamones\driver\Curl;
    use Yurun\Util\YurunHttp\Http\Response;

    require __DIR__ . '/../src/driver/Curl.php';

    class FakeHttpRequest
    {
        public $response;
        public $timeouts;
        public $headersValue = [];

        public function __construct($response)
        {
            $this->response = $response;
        }

        public function timeout($request, $connect)
        {
            $this->timeouts = [$request, $connect];
            return $this;
        }

        public function headers($headers)
        {
            $this->headersValue = $headers;
            return $this;
        }

        protected function response()
        {
            if ($this->response instanceof \Throwable) {
                throw $this->response;
            }

            return $this->response;
        }

        public function post($url, $body, $format)
        {
            return $this->response();
        }

        public function get($url, $body)
        {
            return $this->response();
        }
    }

    class TestCurl extends Curl
    {
        public $responses = [];
        public $clients = [];

        public function queue($response)
        {
            $this->responses[] = $response;
        }

        protected function createHttpClient()
        {
            $client = new FakeHttpRequest(array_shift($this->responses));
            $this->clients[] = $client;
            return $client;
        }

        public function requestState()
        {
            return [
                'headers' => $this->_headers,
                'body' => $this->_body,
                'host' => $this->_host,
            ];
        }
    }

    function assertCurlSame($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . '\nexpected=' . var_export($expected, true)
                . '\nactual=' . var_export($actual, true)
            );
        }
    }

    function assertCurlTrue($value, $message)
    {
        if (!$value) {
            throw new \RuntimeException($message);
        }
    }

    function invokeCurl(TestCurl $curl, $response, $method = 'GET', $headers = [], $route = 'resource')
    {
        $curl->queue($response);
        return $curl->setHost('http://service.local')
            ->setRoute($route)
            ->setMethod($method)
            ->setHeader($headers)
            ->setBody(['id' => 1])
            ->request();
    }

    function configureCurl(TestCurl $curl, $method = 'GET', $headers = [], $route = 'resource')
    {
        return $curl->setHost('http://service.local')
            ->setRoute($route)
            ->setMethod($method)
            ->setHeader($headers)
            ->setBody(['id' => 1]);
    }

    function assertTransientCurlStateCleared(TestCurl $curl, $message)
    {
        assertCurlSame([], $curl->requestState()['headers'], $message . ': headers');
        assertCurlSame(null, $curl->requestState()['body'], $message . ': body');
    }

    function expectCurlException($callback)
    {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            return $e;
        }
        throw new \RuntimeException('Expected RuntimeException was not thrown');
    }

    $GLOBALS['curl_test_config'] = ['discovery' => []];
    $curl = new TestCurl();

    Breaker::reset();
    assertCurlSame(['ok' => true], invokeCurl($curl, new Response(201, '{"ok":true}')), '201 must succeed');
    assertCurlSame(['success'], Breaker::$events, '201 must close breaker failures');

    Breaker::reset();
    assertCurlSame([], invokeCurl($curl, new Response(204, '')), '204 empty body must return an empty array');
    assertCurlSame(['success'], Breaker::$events, '204 must succeed');

    Breaker::reset();
    $error = expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(0, '', 28, 'operation timed out'));
    });
    assertCurlSame(['failure'], Breaker::$events, 'status 0 must trip the breaker');
    assertCurlTrue(strpos($error->getMessage(), 'errno=28') !== false, 'network error must include errno');

    Breaker::reset();
    expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(200, '', 28, 'body timed out'));
    });
    assertCurlSame(['failure'], Breaker::$events, '200 with curl errno must be a transport failure');

    Breaker::reset();
    $error = expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(504, 'secret response body'));
    });
    assertCurlSame(['failure'], Breaker::$events, '504 must trip the breaker');
    assertCurlTrue(strpos($error->getMessage(), 'secret response body') === false, 'response body must not leak');

    Breaker::reset();
    expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(404, '{"message":"missing"}'));
    });
    assertCurlSame(['success'], Breaker::$events, '404 must not trip the breaker');

    Breaker::reset();
    expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(200, 'not-json'));
    });
    assertCurlSame(['success'], Breaker::$events, 'invalid JSON is not a transport failure');
    assertTransientCurlStateCleared($curl, 'invalid JSON must clear transient request state');

    Breaker::reset();
    $error = expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(200, '{"code":123,"msg":"business error"}'));
    });
    assertCurlSame(123, $error->getCode(), 'business error code must be preserved');
    assertCurlSame(['success'], Breaker::$events, 'business errors must not trip the breaker');

    Breaker::reset();
    assertCurlSame(true, invokeCurl($curl, new Response(200, 'true')), 'valid scalar JSON must be returned');

    Breaker::reset();
    invokeCurl($curl, new Response(200, '{}'), 'POST', ['Authorization' => 'Bearer secret']);
    invokeCurl($curl, new Response(200, '{}'), 'POST', ['X-Trace' => 'next']);
    $firstClient = $curl->clients[count($curl->clients) - 2];
    $secondClient = $curl->clients[count($curl->clients) - 1];
    assertCurlTrue(isset($firstClient->headersValue['Authorization']), 'first request must carry authorization');
    assertCurlTrue(!isset($secondClient->headersValue['Authorization']), 'headers must not leak to the next request');
    assertCurlTrue($firstClient !== $secondClient, 'each request must use a fresh HTTP client');

    $GLOBALS['curl_test_config'] = [
        'discovery' => [
            'request_timeout' => 'invalid',
            'connect_timeout' => [],
        ],
    ];
    invokeCurl($curl, new Response(200, '{}'));
    $lastClient = $curl->clients[count($curl->clients) - 1];
    assertCurlSame([30000, 500], $lastClient->timeouts, 'invalid timeouts must use defaults');

    $GLOBALS['curl_test_config'] = [
        'discovery' => [
            'request_timeout' => '100',
            'connect_timeout' => '999',
        ],
    ];
    invokeCurl($curl, new Response(200, '{}'));
    $lastClient = $curl->clients[count($curl->clients) - 1];
    assertCurlSame([100, 100], $lastClient->timeouts, 'connect timeout must not exceed total timeout');

    $GLOBALS['curl_test_config'] = 'invalid top-level config';
    invokeCurl($curl, new Response(200, '{}'));
    $lastClient = $curl->clients[count($curl->clients) - 1];
    assertCurlSame([30000, 500], $lastClient->timeouts, 'non-array config must use defaults');

    $GLOBALS['curl_test_config'] = ['discovery' => []];

    Breaker::reset();
    $error = expectCurlException(function () use ($curl) {
        invokeCurl($curl, new Response(500, ''), 'GET', [], 'resource?token=secret');
    });
    assertCurlTrue(strpos($error->getMessage(), 'token=secret') === false, 'query secrets must not be logged');

    Breaker::reset();
    expectCurlException(function () use ($curl) {
        invokeCurl($curl, null);
    });
    assertCurlSame(['failure'], Breaker::$events, 'non-response values must be transport failures');

    Breaker::reset();
    $sendException = new \InvalidArgumentException('invalid body type', 321);
    try {
        invokeCurl($curl, $sendException, 'POST', ['Authorization' => 'Bearer secret']);
        throw new \RuntimeException('Expected InvalidArgumentException was not thrown');
    } catch (\InvalidArgumentException $e) {
        assertCurlTrue($e === $sendException, 'HTTP client exceptions must be propagated unchanged');
    }
    assertCurlSame([], Breaker::$events, 'programming errors must not trip the breaker');
    assertTransientCurlStateCleared($curl, 'send exceptions must clear transient request state');

    Breaker::reset();
    Breaker::$available = false;
    configureCurl($curl, 'POST', ['Authorization' => 'Bearer secret']);
    expectCurlException(function () use ($curl) {
        $curl->request();
    });
    assertCurlSame([], Breaker::$events, 'an open breaker must not record another failure');
    assertTransientCurlStateCleared($curl, 'breaker rejection must clear transient request state');
    Breaker::$available = true;

    foreach ([408, 429] as $retryableStatus) {
        Breaker::reset();
        expectCurlException(function () use ($curl, $retryableStatus) {
            invokeCurl($curl, new Response($retryableStatus, ''));
        });
        assertCurlSame(
            ['failure'],
            Breaker::$events,
            "{$retryableStatus} must trip the breaker"
        );
    }

    Breaker::reset();
    invokeCurl($curl, new Response(200, '{}'), 'POST', ['Authorization' => 'Bearer once']);
    assertTransientCurlStateCleared($curl, 'successful requests must clear transient request state');
    assertCurlSame(
        'http://service.local',
        $curl->requestState()['host'],
        'host reuse must remain backward compatible'
    );

    echo "CurlTest OK\n";
}
