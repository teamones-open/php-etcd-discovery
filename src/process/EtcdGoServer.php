<?php

namespace teamones\process;

use Ark\Filecache\FileCache;

/**
 * Linux 本地 Go etcd 客户端进程监管器。
 *
 * 通过 runtime 目录 flock 串行化检查、启停和 PID 更新；通过 `/proc/<pid>/cmdline`
 * 的 argv[0] 精确识别当前包内二进制，避免陈旧 PID 或 PID 复用导致误杀。
 *
 * 进程识别不依赖 ext-posix；发信号优先使用 posix_kill，缺失时降级到系统 kill。
 * 运行前提是 Linux `/proc` 可读、启动时 exec/nohup 可用，降级停止时系统 kill 可用。
 */
class EtcdGoServer
{
    // SIGTERM 宽限：restart 500ms，master shutdown 8s；SIGKILL 后最多再确认 500ms。
    protected const RESTART_STOP_WAIT_ATTEMPTS = 5;
    protected const SHUTDOWN_STOP_WAIT_ATTEMPTS = 80;
    protected const FORCE_STOP_WAIT_ATTEMPTS = 5;
    protected const STOP_WAIT_MICROSECONDS = 100000;

    /** @var string */
    protected static $phpEtcdClientPIDKey = "php_etcd_client_pid";

    /** @var FileCache|null */
    protected static $cacheInstance = null;

    /**
     * @return FileCache
     */
    public static function instance()
    {
        if (empty(self::$cacheInstance)) {
            self::$cacheInstance = new FileCache([
                'root' => self::runtimePath(),
                'ttl' => 0,
                'compress' => false,
                'serialize' => 'json',
            ]);
        }

        return self::$cacheInstance;
    }

    /**
     * 确保存在一个当前包的受管 Go 客户端进程，并避免本组件重复拉起。
     *
     * PID 缓存失效时会在进程锁内扫描 `/proc` 并接管同一精确二进制。
     * 本方法只证明进程存在，不证明 8083 端口或 Go 协议仍健康。
     *
     * @return bool
     */
    public static function exec()
    {
        return self::withProcessLock(function () {
            $pid = self::cachedPid();
            if (self::isManagedProcess($pid)) {
                return true;
            }

            self::instance()->delete(self::$phpEtcdClientPIDKey);
            $pid = self::findManagedProcess();
            if ($pid > 0) {
                self::instance()->set(self::$phpEtcdClientPIDKey, $pid);
                return true;
            }

            return self::startProcess();
        });
    }

    /**
     * 重启 OS 层存活但业务已无响应的 Go 客户端。
     *
     * 旧进程未确认退出时绝不启动新进程，避免两个 Go 竞争 8083 端口。
     *
     * @return bool
     */
    public static function restart()
    {
        return self::withProcessLock(function () {
            $pid = self::cachedPid();
            if (!self::isManagedProcess($pid)) {
                $pid = self::findManagedProcess();
            }

            self::instance()->delete(self::$phpEtcdClientPIDKey);
            if ($pid > 0) {
                if (!self::stopProcess($pid, true, self::RESTART_STOP_WAIT_ATTEMPTS)) {
                    return false;
                }
            }

            return self::startProcess();
        });
    }

    /**
     * Stop the managed process during Workerman master shutdown.
     *
     * @return bool
     */
    public static function kill()
    {
        return self::withProcessLock(function () {
            $pid = self::cachedPid();
            self::instance()->delete(self::$phpEtcdClientPIDKey);
            if (!self::isManagedProcess($pid)) {
                $pid = self::findManagedProcess();
            }

            if (!self::isManagedProcess($pid)) {
                return true;
            }

            $stopped = self::stopProcess(
                $pid,
                true,
                self::SHUTDOWN_STOP_WAIT_ATTEMPTS
            );
            if (!$stopped) {
                error_log("Unable to stop php_etcd_client process {$pid}");
            }
            return $stopped;
        });
    }

    /**
     * 读取已持久化的 Go 客户端 PID。
     *
     * ark/filecache 在 Key 不存在时会尝试 fopen() 分片路径。应用自定义的
     * 错误处理器仍可能记录该被 `@` 抑制的预期警告，因此先判断文件是否存在。
     * 本方法在进程锁内调用，不会与本组件自身的 set/delete 并发；
     * 但应用外部仍可能清理 runtime/cache，因此先清除长驻进程的 stat 缓存，再防御检查后的删除竞态。
     *
     * @return int 缓存缺失或无效时返回 0
     */
    protected static function cachedPid()
    {
        $cache = self::instance();
        $cachePath = $cache->getPath(self::$phpEtcdClientPIDKey);
        clearstatcache(true, $cachePath);
        if (!is_file($cachePath)) {
            return 0;
        }

        $value = self::readExistingCacheValue(
            $cache,
            self::$phpEtcdClientPIDKey,
            $cachePath
        );
        // 损坏的 JSON（数组、布尔值等）不能被强转为 PID 1；兼容历史数字字符串。
        if (!is_int($value) && !is_string($value)) {
            return 0;
        }

        $pid = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $pid === false ? 0 : $pid;
    }

    /**
     * 读取已确认存在的 FileCache 值，并将读取瞬间的 ENOENT 视为缓存未命中。
     *
     * 只处理 fopen/file_get_contents 对精确 PID 缓存路径的 ENOENT，兼容库的两次文件读取。
     * 仅消化预期的 E_WARNING；其他错误交还原处理器，保留其返回值及异常，finally 恢复处理器。
     * 返回 true 的已处理告警不会成为新的 error_get_last()，无需清除可能属于业务代码的旧错误。
     *
     * @param FileCache $cache
     * @param string $key
     * @param string $cachePath
     * @return mixed
     */
    protected static function readExistingCacheValue(FileCache $cache, $key, $cachePath)
    {
        $previousHandler = null;
        $missing = false;
        $readPrefixes = ['fopen(' . $cachePath . '): ', 'file_get_contents(' . $cachePath . '): '];
        $previousHandler = set_error_handler(
            static function ($severity, $message, $file, $line, ...$context) use (
                &$previousHandler,
                &$missing,
                $readPrefixes
            ) {
                if ($severity === E_WARNING) {
                    foreach ($readPrefixes as $prefix) {
                        if (strpos($message, $prefix) === 0
                            && strcasecmp(substr($message, strlen($prefix)),
                                'failed to open stream: No such file or directory') === 0
                        ) {
                            $missing = true;
                            return true;
                        }
                    }
                }

                if (is_callable($previousHandler)) {
                    return call_user_func_array(
                        $previousHandler,
                        array_merge([$severity, $message, $file, $line], $context)
                    );
                }

                return false;
            }
        );

        try {
            $value = $cache->get($key);
            return $missing ? false : $value;
        } finally {
            restore_error_handler();
            clearstatcache(true, $cachePath);
        }
    }

    /**
     * 后台启动当前包内二进制并记录 PID。
     *
     * 启动后 100ms 检查只确认 PID 已进入目标二进制，不代表 8083 已 ready；
     * 连接 watchdog 会继续检测实际可用性。
     *
     * @return bool
     */
    protected static function startProcess()
    {
        $binary = self::binaryPath();
        if (!is_executable($binary)) {
            throw new \RuntimeException("php_etcd_client is not executable: {$binary}");
        }

        $output = [];
        $exitCode = 0;
        $command = 'nohup ' . escapeshellarg($binary) . ' >/dev/null 2>&1 & echo $!';
        \exec($command, $output, $exitCode);

        $pid = isset($output[0]) ? (int)trim($output[0]) : 0;
        if ($exitCode !== 0 || $pid <= 0) {
            return false;
        }

        self::instance()->set(self::$phpEtcdClientPIDKey, $pid);
        usleep(self::STOP_WAIT_MICROSECONDS);
        if (!self::isManagedProcess($pid)) {
            self::instance()->delete(self::$phpEtcdClientPIDKey);
            return false;
        }

        return true;
    }

    /**
     * 停止已经通过精确身份校验的 Go 进程。
     *
     * PID 不再能识别为当前受管进程时视为已停止。先发 SIGTERM 并轮询 `/proc`；`$force=true` 时宽限后
     * 升级为 SIGKILL。信号发送成功不等于进程已退出，必须继续确认身份消失。
     *
     * @param int $pid
     * @param bool $force
     * @param int $graceAttempts
     * @return bool
     */
    protected static function stopProcess($pid, $force, $graceAttempts)
    {
        if (!self::isManagedProcess($pid)) {
            return true;
        }

        $sigterm = defined('SIGTERM') ? SIGTERM : 15;
        if (!self::sendSignal($pid, $sigterm)) {
            return !self::isManagedProcess($pid);
        }

        for ($attempt = 0; $attempt < $graceAttempts; $attempt++) {
            usleep(self::STOP_WAIT_MICROSECONDS);
            if (!self::isManagedProcess($pid)) {
                return true;
            }
        }

        if (!$force || !self::isManagedProcess($pid)) {
            return !self::isManagedProcess($pid);
        }

        $sigkill = defined('SIGKILL') ? SIGKILL : 9;
        if (!self::sendSignal($pid, $sigkill)) {
            return !self::isManagedProcess($pid);
        }

        for ($attempt = 0; $attempt < self::FORCE_STOP_WAIT_ATTEMPTS; $attempt++) {
            usleep(self::STOP_WAIT_MICROSECONDS);
            if (!self::isManagedProcess($pid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 向目标 PID 提交信号。
     *
     * 优先使用 posix_kill；无 ext-posix 时使用系统 kill。命令中 signal/PID
     * 强制转换为整数，避免命令注入。返回值只表示信号已成功提交，不表示进程已退出。
     *
     * @param int $pid
     * @param int $signal
     * @return bool
     */
    protected static function sendSignal($pid, $signal)
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, $signal);
        }

        if (!function_exists('exec')) {
            return false;
        }

        $output = [];
        $exitCode = 1;
        $command = 'kill -' . (int)$signal . ' ' . (int)$pid . ' >/dev/null 2>&1';
        \exec($command, $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * 精确校验 PID 是否属于当前包内 Go 二进制。
     *
     * 读取 `/proc/<pid>/cmdline` 的 argv[0]，经 realpath 后必须与当前 binary 完全一致。
     * 这既避免陈旧 PID/PID 复用误杀其他进程，也使存活检查不依赖 ext-posix。
     * 本方法只验证进程身份，不验证端口或业务响应。
     *
     * @param int $pid
     * @return bool
     */
    protected static function isManagedProcess($pid)
    {
        if ($pid <= 0) {
            return false;
        }

        $cmdlinePath = "/proc/{$pid}/cmdline";
        if (!is_readable($cmdlinePath)) {
            return false;
        }

        $cmdline = @file_get_contents($cmdlinePath);
        if (!is_string($cmdline) || $cmdline === '') {
            return false;
        }

        $arguments = explode("\0", $cmdline);
        $executable = realpath($arguments[0] ?? '');
        return $executable !== false && $executable === self::binaryPath();
    }

    /**
     * 扫描 `/proc` 并仅接管与当前 binary realpath 完全一致的进程。
     *
     * 不使用模糊进程名，因此不会接管其他应用或其他版本路径中的同名程序。
     *
     * @return int 找到时返回 PID，否则返回 0
     */
    protected static function findManagedProcess()
    {
        $cmdlineFiles = glob('/proc/[0-9]*/cmdline');
        if (!is_array($cmdlineFiles)) {
            return 0;
        }

        foreach ($cmdlineFiles as $cmdlineFile) {
            $pid = (int)basename(dirname($cmdlineFile));
            if (self::isManagedProcess($pid)) {
                return $pid;
            }
        }

        return 0;
    }

    /**
     * 在跨 PHP Worker 的独占文件锁内执行完整进程事务。
     *
     * 锁的范围必须覆盖身份检查、停止、启动和 PID 更新，否则多 Worker
     * 同时检查时仍可能重复拉起。runtime 文件系统必须支持 flock。
     *
     * @param callable $callback
     * @return mixed
     */
    protected static function withProcessLock($callback)
    {
        $lockPath = self::runtimePath() . DIRECTORY_SEPARATOR . 'php_etcd_client.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if ($lockHandle === false) {
            throw new \RuntimeException("Unable to open php_etcd_client lock: {$lockPath}");
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            throw new \RuntimeException("Unable to lock php_etcd_client lock: {$lockPath}");
        }

        try {
            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    protected static function runtimePath()
    {
        $config = config('etcd', []);
        if (!is_array($config)
            || !isset($config['discovery'])
            || !is_array($config['discovery'])
        ) {
            throw new \RuntimeException("Etcd connection discovery not found");
        }

        $cachePath = $config['discovery']['cache'] ?? '';
        if (!is_string($cachePath) || trim($cachePath) === '') {
            $cachePath = __DIR__ . '/../log';
        }
        $cachePath = rtrim($cachePath, '/\\');

        if (!is_dir($cachePath) && !@mkdir($cachePath, 0775, true) && !is_dir($cachePath)) {
            throw new \RuntimeException("Unable to create etcd runtime path: {$cachePath}");
        }

        return $cachePath;
    }

    protected static function binaryPath()
    {
        $binary = realpath(__DIR__ . '/../../bin/php_etcd_client');
        if ($binary === false || !is_file($binary)) {
            throw new \RuntimeException("php_etcd_client binary not found");
        }

        return $binary;
    }
}
