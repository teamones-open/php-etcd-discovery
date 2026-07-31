<?php

namespace teamones\etcd;

use teamones\cache\Client;
use teamones\Log;

/**
 * 管理 Go 客户端返回的服务发现结果。
 *
 * v2 缓存为每个服务使用独立 Redis Key/TTL；新版读取器永不回退旧聚合 Key，
 * 以免已下线节点被过期数据“复活”。
 */
class Discovery
{
    // 单位为秒；每个 v2 服务 Key（包括空地址 tombstone）独立计时。
    protected const DEFAULT_CACHE_TTL = 5;

    /**
     * @var object 对象实例
     */
    protected static $instance = null;


    /**
     * v2 Key 拼接格式："etcd_discovery" + server_uuid + ":v2:" + sha256(server_name)。
     *
     * UUID 隔离消费者实例，服务名哈希用于固定 Key 长度并避免特殊字符。
     *
     * @var string
     */
    protected $cacheKeyPrefix = '';

    /**
     * 旧 SDK 使用的整表 JSON Key，所有服务共享一个 TTL，仅供迁移期双写。
     *
     * @var string
     */
    protected $legacyCacheKey = '';

    /**
     * 是否双写旧聚合 Key，默认关闭。
     *
     * 只有旧新 PHP Worker 共存时才临时开启；新版始终只读 v2。长期开启会
     * 增加 Redis GET/SET，并继续保留旧协议“整表共享 TTL”的限制。
     *
     * @var bool
     */
    protected $legacyCacheWrite = false;

    /** @var int 每个 v2 服务的独立有效期（秒） */
    protected $cacheTtl = self::DEFAULT_CACHE_TTL;


    // 注册参数
    protected $serverInfo = [
        'method' => 'discovery',
        'etcd_host' => '',
        'param' => ''
    ];

    /**
     * 初始化当前消费者的 Redis Key 命名空间和缓存策略。
     *
     * @throws \RuntimeException 服务发现配置或 server_uuid 缺失时抛出
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
     * 将服务发现结果写入缓存。
     *
     * v2 是主写入；legacy 仅是 best-effort 兼容写。`server_host === ''`
     * 是“服务不可用” tombstone，必须照常缓存，不能删除或忽略。
     *
     * @param string $name
     * @param array $discoveryData
     * @return void
     * @throws \RuntimeException v2 JSON 编码失败，或 Redis 写入抛出异常时向上传递
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
        // Empty server_host is an intentional tombstone and must be cached as-is.
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
     * 只从 v2 Key 读取指定服务。
     *
     * Key 缺失、过期或损坏时直接返回空数组，绝不回退 legacy，即使旧 Key
     * 仍保留地址。返回值可能是包含空 server_host 的 tombstone。
     *
     * @param string $name
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

    /**
     * 生成稳定、定长的服务名哈希后缀；SHA-256 仅用于 Key 规范化，不承担安全职责。
     *
     * @param string $name
     * @return string
     */
    protected function serviceCacheKey($name)
    {
        return $this->cacheKeyPrefix . hash('sha256', (string)$name);
    }

    /**
     * 迁移期为旧读取器刷新整表 JSON Key。
     *
     * 旧协议没有服务级 TTL，GET -> SET 也存在并发覆盖限制。调用方必须隔离
     * 本方法的所有异常，新版读取器不得依赖此 Key。
     *
     * @param string $name
     * @param array $discoveryData
     * @return void
     */
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

    /**
     * best-effort 记录兼容写失败。
     *
     * logger 未配置或 handler 自身抛异常时降级到 error_log，日志链路绝不能
     * 反向破坏已经成功的 v2 主写入。
     *
     * @param \Throwable $exception
     * @return void
     */
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
