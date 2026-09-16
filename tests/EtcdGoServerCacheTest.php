<?php

namespace teamones\process {
    function config($name, $default = [])
    {
        return $GLOBALS['etcd_go_server_test_config'] ?? $default;
    }
}

namespace {
    // 优先使用显式指定的真实 0.1.0 源码或 Composer 依赖，无 vendor 时才使用替身。
    $sourceDirectory = getenv('ETCD_FILECACHE_SOURCE_DIR');
    if ($sourceDirectory !== false && $sourceDirectory !== '') {
        require $sourceDirectory . '/Util.php';
        require $sourceDirectory . '/FileCache.php';
        $dependencyMode = 'real source';
    } elseif (is_file(__DIR__ . '/../vendor/autoload.php')) {
        require __DIR__ . '/../vendor/autoload.php';
        $dependencyMode = 'composer';
    } else {
        require __DIR__ . '/fixtures/FileCacheStub.php';
        $dependencyMode = 'stub';
    }
    require __DIR__ . '/../src/process/EtcdGoServer.php';

    class RaceFileCache extends \Ark\Filecache\FileCache
    {
        public $beforeGet;
        public $afterMetadata;
        public $getCalls = 0;

        public function get($key)
        {
            $this->getCalls++;
            if ($this->beforeGet) {
                ($this->beforeGet)($this, $key);
            }
            return parent::get($key);
        }

        public function getMeta($key)
        {
            $meta = parent::getMeta($key);
            if ($meta && $this->afterMetadata) {
                ($this->afterMetadata)($this, $key);
            }
            return $meta;
        }
    }

    function assertPidSame($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($message . ': expected=' . var_export($expected, true)
                . ', actual=' . var_export($actual, true));
        }
    }

    function currentPidTestHandler()
    {
        $handler = set_error_handler(static function () { return false; });
        restore_error_handler();
        return $handler;
    }

    $runtimePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'etcd-pid-review-' . bin2hex(random_bytes(8));
    mkdir($runtimePath, 0775, true);
    $GLOBALS['etcd_go_server_test_config'] = ['discovery' => ['cache' => $runtimePath]];
    $cache = new RaceFileCache(['root' => $runtimePath, 'serialize' => 'json']);
    $key = 'php_etcd_client_pid';
    $path = $cache->getPath($key);
    $cacheProperty = new \ReflectionProperty(\teamones\process\EtcdGoServer::class, 'cacheInstance');
    $cacheProperty->setAccessible(true);
    $cacheProperty->setValue(null, $cache);
    $cachedPid = new \ReflectionMethod(\teamones\process\EtcdGoServer::class, 'cachedPid');
    $cachedPid->setAccessible(true);
    $strictHandler = static function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    };
    set_error_handler($strictHandler);
    $cases = 0;

    try {
        assertPidSame(0, $cachedPid->invoke(null), 'missing file is a cache miss');
        assertPidSame(0, $cache->getCalls, 'missing file must skip FileCache::get');
        $cases++;

        $cache->set($key, 4321);
        assertPidSame(4321, $cachedPid->invoke(null), 'normal persisted PID round trip');
        assertPidSame($strictHandler, currentPidTestHandler(), 'handler restored after successful read');
        $cases++;

        // 子进程删除不清除当前长驻进程的 stat 缓存；必须重新检查，不能继续读取。
        assertPidSame(true, is_file($path), 'warm positive stat cache');
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/DeletePidCache.php', $path], [], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException('external PID cache deletion failed');
        }
        $getCalls = $cache->getCalls;
        assertPidSame(0, $cachedPid->invoke(null), 'externally deleted cache is missing');
        assertPidSame($getCalls, $cache->getCalls, 'stale stat cache must not cause FileCache::get');
        $cases++;

        foreach ([false, null, true, [], [123], -2, 0, 1.5, '123junk', '99999999999999999999999'] as $invalid) {
            $cache->set($key, $invalid);
            assertPidSame(0, $cachedPid->invoke(null), 'invalid PID must not be coerced');
        }
        $cache->set($key, '4321');
        assertPidSame(4321, $cachedPid->invoke(null), 'legacy numeric strings remain compatible');
        $cases++;

        // 覆盖两个读取边界：is_file -> getMeta/fopen，以及 getMeta -> file_get_contents。
        foreach (['beforeGet', 'afterMetadata'] as $hook) {
            $cache->set($key, 4321);
            $cache->$hook = static function ($cache, $key) { unlink($cache->getPath($key)); };
            error_clear_last();
            try {
                assertPidSame(0, $cachedPid->invoke(null), 'deletion at ' . $hook . ' is a cache miss');
                assertPidSame(null, error_get_last(), 'handled ENOENT must not pollute error_get_last');
                assertPidSame($strictHandler, currentPidTestHandler(), 'handler restored after ' . $hook);
            } finally {
                $cache->$hook = null;
            }
            $cases++;
        }

        // @ 告警可能残留；新读取不应抹掉此前属于业务代码的错误。
        set_error_handler(static function () { return false; });
        @trigger_error('pre-existing application warning', E_USER_WARNING);
        $previousError = error_get_last();
        restore_error_handler();
        $cache->set($key, 4321);
        $cache->beforeGet = static function ($cache, $key) { unlink($cache->getPath($key)); };
        assertPidSame(0, $cachedPid->invoke(null), 'expected missing read');
        assertPidSame($previousError, error_get_last(), 'unrelated last error must be preserved');
        $cache->beforeGet = null;
        $cases++;

        // 精确路径和操作匹配：同路径权限/磁盘错误、相邻路径和删除失败必须透传。
        foreach ([
            'fopen(' . $path . '): failed to open stream: Permission denied',
            'fopen(' . $path . '): failed to open stream: Input/output error',
            'fopen(' . $path . '.other): failed to open stream: No such file or directory',
            'unlink(' . $path . '): No such file or directory',
        ] as $warning) {
            $cache->set($key, 4321);
            $cache->beforeGet = static function () use ($warning) {
                // 稳定模拟 OS 级 E_WARNING，避免依赖测试机的权限/磁盘状态。
                $handler = currentPidTestHandler();
                $handler(E_WARNING, $warning, __FILE__, __LINE__);
            };
            try {
                $cachedPid->invoke(null);
                throw new \RuntimeException('non-ENOENT warning was swallowed: ' . $warning);
            } catch (\ErrorException $exception) {
                assertPidSame($warning, $exception->getMessage(), 'original warning must propagate');
            } finally {
                $cache->beforeGet = null;
            }
            assertPidSame($strictHandler, currentPidTestHandler(), 'handler restored after original handler throws');
        }
        $cases++;

        $cache->set($key, 4321);
        $forwardCalls = 0;
        $falseHandler = static function () use (&$forwardCalls) { $forwardCalls++; return false; };
        set_error_handler($falseHandler);
        $cache->beforeGet = static function () use ($path) {
            $handler = currentPidTestHandler();
            assertPidSame(false, $handler(E_WARNING,
                'fopen(' . $path . '): failed to open stream: Permission denied', __FILE__, __LINE__),
                'false return value must preserve PHP default error handling');
        };
        try {
            assertPidSame(4321, $cachedPid->invoke(null), 'read after delegated warning');
            assertPidSame(1, $forwardCalls, 'original handler called once');
            assertPidSame($falseHandler, currentPidTestHandler(), 'false handler restored');
        } finally {
            $cache->beforeGet = null;
            restore_error_handler();
        }
        $cases++;

        $cache->beforeGet = static function () { throw new \RuntimeException('read failed'); };
        try {
            $cachedPid->invoke(null);
            throw new \RuntimeException('missing exception');
        } catch (\RuntimeException $exception) {
            assertPidSame('read failed', $exception->getMessage(), 'cache exception must propagate');
        } finally {
            $cache->beforeGet = null;
        }
        assertPidSame($strictHandler, currentPidTestHandler(), 'handler restored after cache exception');
        $cases++;

        // 临时处理器不能绕过原处理器对非 E_WARNING 错误的处理。
        $cache->beforeGet = static function () { trigger_error('application notice', E_USER_NOTICE); };
        try {
            $cachedPid->invoke(null);
            throw new \RuntimeException('application notice was swallowed');
        } catch (\ErrorException $exception) {
            assertPidSame('application notice', $exception->getMessage(), 'notice must reach original handler');
        } finally {
            $cache->beforeGet = null;
        }
        assertPidSame($strictHandler, currentPidTestHandler(), 'handler restored after notice');
        $cases++;

        set_error_handler(null);
        $cache->beforeGet = static function ($cache, $key) { unlink($cache->getPath($key)); };
        try {
            error_clear_last();
            assertPidSame(0, $cachedPid->invoke(null), 'missing read without prior handler');
            assertPidSame(null, error_get_last(), 'no default ENOENT warning leaked');
            assertPidSame(null, currentPidTestHandler(), 'null handler restored');
        } finally {
            $cache->beforeGet = null;
            restore_error_handler();
        }
        $cases++;
    } finally {
        restore_error_handler();
        // 仅清理本测试刚创建的随机目录，递归不跟随符号链接。
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($runtimePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($runtimePath);
    }

    echo "EtcdGoServerCacheTest OK ({$dependencyMode}, {$cases} cases)\n";
}
