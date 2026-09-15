<?php

namespace Ark\Filecache {
    class FileCache
    {
        public static $getCalls = 0;
        protected $root;

        public function __construct($options = [])
        {
            $this->root = $options['root'];
        }

        public function getPath($key)
        {
            $hash = md5($key);
            return $this->root . '/' . $hash[0] . '/' . $hash[1] . '/'
                . $hash[2] . '/' . substr($hash, 3);
        }

        public function get($key)
        {
            self::$getCalls++;
            return false;
        }
    }
}

namespace teamones\process {
    function config($name, $default = [])
    {
        return $GLOBALS['etcd_go_server_test_config'] ?? $default;
    }
}

namespace {
    use Ark\Filecache\FileCache;
    use teamones\process\EtcdGoServer;

    function assertEtcdGoServerSame($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . '\nexpected=' . var_export($expected, true)
                . '\nactual=' . var_export($actual, true)
            );
        }
    }

    $runtimePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'php-etcd-discovery-cache-test-' . getmypid();
    if (!is_dir($runtimePath) && !mkdir($runtimePath, 0775, true) && !is_dir($runtimePath)) {
        throw new \RuntimeException('unable to create test runtime path');
    }

    $GLOBALS['etcd_go_server_test_config'] = [
        'discovery' => [
            'cache' => $runtimePath,
        ],
    ];

    require __DIR__ . '/../src/process/EtcdGoServer.php';

    $cachedPid = new \ReflectionMethod(EtcdGoServer::class, 'cachedPid');
    $cachedPid->setAccessible(true);

    assertEtcdGoServerSame(
        0,
        $cachedPid->invoke(null),
        'missing PID cache must be treated as an empty cache'
    );
    assertEtcdGoServerSame(
        0,
        FileCache::$getCalls,
        'missing PID cache must not call FileCache::get or trigger fopen'
    );

    if (!rmdir($runtimePath)) {
        throw new \RuntimeException('unable to remove test runtime path');
    }

    echo "EtcdGoServerCacheTest OK\n";
}
