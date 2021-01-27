<?php

namespace teamones\process;

use Ark\Filecache\FileCache;

class EtcdGoServer
{

    // php_etcd_client 进程id
    protected static $phpEtcdClientPIDKey = "php_etcd_client_pid";

    protected static $cacheInstance = null;

    /**
     * @return FileCache|null
     */
    public static function instance()
    {
        if (empty(self::$cacheInstance)) {
            $config = config('etcd', []);
            if (!isset($config['discovery'])) {
                throw new \RuntimeException("Etcd connection discovery not found");
            }

            // 文件缓存
            $cachePath = $config['discovery']['cache'] ?? __DIR__ . '/../log';
            self::$cacheInstance = new FileCache([
                'root' => $cachePath, // Cache root
                'ttl' => 0,
                'compress' => false,
                'serialize' => 'json',
            ]);
        }
        return self::$cacheInstance;
    }

    /**
     * 后台运行 go etcd ws 服务端
     */
    public static function exec()
    {
        // 判断当前ws进程是否存在
        $cmd = 'ps axu|grep "php_etcd_client"|grep -v "grep"|wc -l';
        $ret = shell_exec("$cmd");

        $ret = rtrim($ret, "\r\n");

        if ($ret === "0") {
            // 拉起 php_etcd_client 客户端并后台运行
            exec("nohup " . __DIR__ . "/../../bin/php_etcd_client >/dev/null 2>&1 & echo $!", $output);

            // 记录 php_etcd_client 进程 id
            if (!empty($output[0])) {
                self::instance()->set(self::$phpEtcdClientPIDKey, (int)$output[0]);
            }
        }
    }

    /**
     * 杀死 go etcd ws 服务端
     */
    public static function kill()
    {
        // 关闭杀死 php_etcd_client
        $phpEtcdClientPID = self::instance()->get(self::$phpEtcdClientPIDKey);
        if ($phpEtcdClientPID > 0) {
            self::instance()->delete(self::$phpEtcdClientPIDKey);
            \posix_kill($phpEtcdClientPID, SIGKILL);
        }
    }
}