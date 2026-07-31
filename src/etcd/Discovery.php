<?php

namespace teamones\etcd;

use teamones\cache\Client;
use teamones\Log;

class Discovery
{
    protected const DEFAULT_CACHE_TTL = 5;

    /**
     * @var object 对象实例
     */
    protected static $instance = null;


    /**
     * Cache key prefix scoped to the current consumer instance.
     *
     * @var string
     */
    protected $cacheKeyPrefix = '';

    /** @var string */
    protected $legacyCacheKey = '';

    /**
     * Keep old workers alive during a rolling upgrade. New workers never read
     * this aggregate key because its entries do not have independent ages.
     *
     * @var bool
     */
    protected $legacyCacheWrite = false;

    /** @var int */
    protected $cacheTtl = self::DEFAULT_CACHE_TTL;


    // 注册参数
    protected $serverInfo = [
        'method' => 'discovery',
        'etcd_host' => '',
        'param' => ''
    ];

    /**
     * Discovery constructor.
     */
    public function __construct()
    {
        $config = config('etcd', []);
        if (!is_array($config)
            || !isset($config['discovery'])
            || !is_array($config['discovery'])
        ) {
            throw new \RuntimeException("Etcd connection discovery not found");
        }
        $discoveryConfig = $config['discovery'];

        $uuid = Registry::$serverUUID;
        if (empty($uuid)) {
            $uuid = $discoveryConfig['server_uuid'] ?? '';
        }

        if (empty($uuid)) {
            throw new \RuntimeException("Etcd discovery server_uuid not found");
        }

        $this->legacyCacheKey = "etcd_discovery" . $uuid;
        $this->cacheKeyPrefix = $this->legacyCacheKey . ':v2:';
        $this->cacheTtl = $this->normalizeCacheTtl(
            $discoveryConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL
        );
        $this->legacyCacheWrite = $this->normalizeBoolean(
            $discoveryConfig['legacy_cache_write'] ?? false,
            false
        );
    }

    /**
     * 初始化
     * @param string $etcdHost
     * @return object|static
     */
    public static function instance()
    {
        if (!empty(self::$instance)) {
            return self::$instance;
        }

        self::$instance = new static();
        return self::$instance;
    }

    /**
     * 获取服务发现参数配置
     * @param string $serverName
     * @param int $serverPort
     * @return array
     */
    public function generateParam($serverName = '', $serverPort = "8080")
    {
        // etcd 地址
        $this->serverInfo['etcd_host'] = Registry::$serverEtcdHost;

        $this->serverInfo['param'] = json_encode([
            'uuid' => (string)Registry::$serverUUID,
            'name' => (string)$serverName,
            'port' => (string)$serverPort
        ]);

        return $this->serverInfo;
    }

    /**
     * 把服务地址写入缓存
     * @param $name
     * @param array $discoveryData
     */
    public function refreshCache($name, $discoveryData = [])
    {
        if ($name === '') {
            return;
        }

        $cacheValue = json_encode($discoveryData);
        if ($cacheValue === false) {
            throw new \RuntimeException("Etcd discovery cache encode failed");
        }

        // One key per service gives every discovery result an independent TTL
        // and avoids lost updates from concurrent read-modify-write operations.
        Client::set($this->serviceCacheKey($name), $cacheValue, 'EX', $this->cacheTtl);

        if ($this->legacyCacheWrite) {
            try {
                $this->refreshLegacyCache($name, $discoveryData);
            } catch (\Throwable $e) {
                $this->logLegacyCacheFailure($e);
            }
        }
    }

    /**
     * 通过服务名称获取服务配置
     * @param $name
     * @return array
     */
    public function getServerConfigByName($name)
    {
        if ($name === '') {
            return [];
        }

        $cacheKey = $this->serviceCacheKey($name);
        $cache = Client::get($cacheKey);
        if ($cache === false || $cache === null || $cache === '') {
            return [];
        }

        $serverConfig = json_decode($cache, true);
        if (!is_array($serverConfig)) {
            Client::del($cacheKey);
            return [];
        }

        return $serverConfig;
    }

    protected function serviceCacheKey($name)
    {
        return $this->cacheKeyPrefix . hash('sha256', (string)$name);
    }

    protected function refreshLegacyCache($name, array $discoveryData)
    {
        $cache = Client::get($this->legacyCacheKey);
        $cacheArray = is_string($cache) ? json_decode($cache, true) : [];
        if (!is_array($cacheArray)) {
            $cacheArray = [];
        }

        $cacheArray[$name] = $discoveryData;
        $cacheValue = json_encode($cacheArray);
        if ($cacheValue === false) {
            throw new \RuntimeException("Etcd legacy discovery cache encode failed");
        }

        Client::set($this->legacyCacheKey, $cacheValue, 'EX', $this->cacheTtl);
    }

    protected function logLegacyCacheFailure(\Throwable $exception)
    {
        $message = 'Etcd legacy discovery cache write failed: ' . $exception->getMessage();

        try {
            $logger = Log::channel();
            if (is_object($logger) && is_callable([$logger, 'error'])) {
                $logger->error($message);
                return;
            }
        } catch (\Throwable $loggingException) {
            // Logging must never turn an optional compatibility write into a
            // failure of the primary v2 discovery cache.
        }

        error_log($message);
    }

    protected function normalizeCacheTtl($value)
    {
        if (is_int($value)) {
            $ttl = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $ttl = (int)trim($value);
        } else {
            return self::DEFAULT_CACHE_TTL;
        }

        return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
    }

    protected function normalizeBoolean($value, $default)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : $default);
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        return $default;
    }
}
