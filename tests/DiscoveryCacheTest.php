<?php

namespace teamones {
    class DiscoveryTestLogger
    {
        public $messages = [];

        public function error($message)
        {
            $this->messages[] = $message;
        }
    }

    class Log
    {
        public static $logger;
        public static $returnNull = false;

        public static function channel()
        {
            if (self::$returnNull) {
                return null;
            }

            if (!self::$logger) {
                self::$logger = new DiscoveryTestLogger();
            }

            return self::$logger;
        }
    }
}

namespace teamones\cache {
    class Client
    {
        public static $now = 0;
        public static $values = [];
        public static $setKeys = [];
        public static $setCalls = [];
        public static $failLegacyWrite = false;

        public static function set($key, $value, $mode = null, $ttl = null)
        {
            if (self::$failLegacyWrite && strpos($key, ':v2:') === false) {
                throw new \RuntimeException('legacy Redis unavailable');
            }

            self::$values[$key] = [
                'value' => $value,
                'expires_at' => self::$now + (int)$ttl,
            ];
            self::$setKeys[] = $key;
            self::$setCalls[] = [
                'key' => $key,
                'value' => $value,
                'mode' => $mode,
                'ttl' => $ttl,
            ];
            return true;
        }

        public static function get($key)
        {
            if (!isset(self::$values[$key])) {
                return false;
            }
            if (self::$values[$key]['expires_at'] <= self::$now) {
                unset(self::$values[$key]);
                return false;
            }
            return self::$values[$key]['value'];
        }

        public static function del($key)
        {
            unset(self::$values[$key]);
            return 1;
        }
    }
}

namespace teamones\etcd {
    class Registry
    {
        public static $serverUUID = '';
        public static $serverEtcdHost = '127.0.0.1:2379';
    }

    function config($name, $default = [])
    {
        return $GLOBALS['discovery_test_config'] ?? $default;
    }

    function error_log($message)
    {
        $GLOBALS['discovery_test_error_log'][] = $message;
        return true;
    }
}

namespace {
    use teamones\cache\Client;
    use teamones\etcd\Discovery;
    use teamones\etcd\Registry;

    function assertSameValue($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . '\nexpected=' . var_export($expected, true)
                . '\nactual=' . var_export($actual, true)
            );
        }
    }

    $GLOBALS['discovery_test_config'] = [
        'discovery' => [
            'server_uuid' => 'consumer-1',
            'cache_ttl' => '5',
            'legacy_cache_write' => true,
        ],
    ];

    require __DIR__ . '/../src/etcd/Discovery.php';

    Registry::$serverUUID = '';
    $discovery = new Discovery();
    $discovery->refreshCache('service-a', [
        'server_name' => 'service-a',
        'server_host' => '10.0.0.1:8080',
    ]);
    $discovery->refreshCache('service-b', [
        'server_name' => 'service-b',
        'server_host' => '10.0.0.2:8080',
    ]);

    $v2Keys = array_values(array_filter(array_unique(Client::$setKeys), function ($key) {
        return strpos($key, ':v2:') !== false;
    }));
    assertSameValue(2, count($v2Keys), 'services must use independent v2 keys');

    foreach (Client::$setCalls as $setCall) {
        assertSameValue('EX', $setCall['mode'], 'cache writes must use Redis EX mode');
        assertSameValue(5, $setCall['ttl'], 'cache writes must use the configured TTL');
    }

    $legacyKey = 'etcd_discoveryconsumer-1';
    $legacyData = json_decode(Client::get($legacyKey), true);
    assertSameValue(
        '10.0.0.1:8080',
        $legacyData['service-a']['server_host'],
        'legacy readers must be served during rolling upgrade'
    );

    Client::$now = 4;
    $discovery->refreshCache('service-b', [
        'server_name' => 'service-b',
        'server_host' => '10.0.0.3:8080',
    ]);
    Client::$now = 6;

    assertSameValue([], $discovery->getServerConfigByName('service-a'), 'service-a must expire independently');
    assertSameValue(
        '10.0.0.1:8080',
        json_decode(Client::get($legacyKey), true)['service-a']['server_host'],
        'legacy aggregate may still contain service-a and must never be used by the new reader'
    );
    assertSameValue(
        '10.0.0.3:8080',
        $discovery->getServerConfigByName('service-b')['server_host'],
        'refreshing service-b must keep only service-b alive'
    );

    $discovery->refreshCache('service-b', [
        'server_name' => 'service-b',
        'server_host' => '',
    ]);
    assertSameValue(
        '',
        $discovery->getServerConfigByName('service-b')['server_host'],
        'an unavailable service must be cached as a tombstone'
    );

    Client::$values[$legacyKey] = [
        'value' => json_encode([
            'service-b' => [
                'server_name' => 'service-b',
                'server_host' => '10.0.0.99:8080',
            ],
        ]),
        'expires_at' => Client::$now + 5,
    ];
    assertSameValue(
        '',
        $discovery->getServerConfigByName('service-b')['server_host'],
        'a v2 tombstone must not fall back to a stale legacy host'
    );

    $GLOBALS['discovery_test_config']['discovery']['server_uuid'] = 'consumer-2';
    $GLOBALS['discovery_test_config']['discovery']['legacy_cache_write'] = 'false';
    $noLegacyDiscovery = new Discovery();
    $setCallCount = count(Client::$setCalls);
    $noLegacyDiscovery->refreshCache('service-a', [
        'server_name' => 'service-a',
        'server_host' => '10.0.0.4:8080',
    ]);
    $newCalls = array_slice(Client::$setCalls, $setCallCount);
    assertSameValue(1, count($newCalls), 'disabled legacy compatibility must write only v2');
    assertSameValue(
        false,
        Client::get('etcd_discoveryconsumer-2'),
        'disabled legacy compatibility must not create the aggregate key'
    );

    $GLOBALS['discovery_test_config']['discovery']['server_uuid'] = 'consumer-default';
    unset($GLOBALS['discovery_test_config']['discovery']['legacy_cache_write']);
    $defaultDiscovery = new Discovery();
    $setCallCount = count(Client::$setCalls);
    $defaultDiscovery->refreshCache('service-a', [
        'server_name' => 'service-a',
        'server_host' => '10.0.0.7:8080',
    ]);
    $newCalls = array_slice(Client::$setCalls, $setCallCount);
    assertSameValue(1, count($newCalls), 'legacy compatibility must default to v2-only writes');
    assertSameValue(
        false,
        Client::get('etcd_discoveryconsumer-default'),
        'omitted legacy compatibility must not create the aggregate key'
    );

    $GLOBALS['discovery_test_config']['discovery']['server_uuid'] = 'consumer-3';
    $GLOBALS['discovery_test_config']['discovery']['legacy_cache_write'] = true;
    Client::$failLegacyWrite = true;
    $legacyFailureDiscovery = new Discovery();
    $legacyFailureDiscovery->refreshCache('service-a', [
        'server_name' => 'service-a',
        'server_host' => '10.0.0.5:8080',
    ]);
    Client::$failLegacyWrite = false;
    assertSameValue(
        '10.0.0.5:8080',
        $legacyFailureDiscovery->getServerConfigByName('service-a')['server_host'],
        'optional legacy write failure must not roll back the v2 cache'
    );
    assertSameValue(
        1,
        count(\teamones\Log::channel()->messages),
        'optional legacy write failure must be logged once'
    );

    $GLOBALS['discovery_test_config']['discovery']['server_uuid'] = 'consumer-4';
    \teamones\Log::$returnNull = true;
    Client::$failLegacyWrite = true;
    $noLoggerDiscovery = new Discovery();
    $noLoggerDiscovery->refreshCache('service-a', [
        'server_name' => 'service-a',
        'server_host' => '10.0.0.6:8080',
    ]);
    Client::$failLegacyWrite = false;
    \teamones\Log::$returnNull = false;
    assertSameValue(
        '10.0.0.6:8080',
        $noLoggerDiscovery->getServerConfigByName('service-a')['server_host'],
        'missing logger must not turn optional legacy failure into v2 failure'
    );
    assertSameValue(
        1,
        count($GLOBALS['discovery_test_error_log']),
        'missing logger must fall back to error_log'
    );

    echo "DiscoveryCacheTest OK\n";
}
