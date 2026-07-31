<?php

namespace teamones\process;

use Ark\Filecache\FileCache;

class EtcdGoServer
{
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
     * Ensure that exactly one local Go client process is running.
     *
     * @return bool
     */
    public static function exec()
    {
        return self::withProcessLock(function () {
            $pid = (int)self::instance()->get(self::$phpEtcdClientPIDKey);
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
     * Restart a client that is alive at the OS level but no longer responsive.
     *
     * @return bool
     */
    public static function restart()
    {
        return self::withProcessLock(function () {
            $pid = (int)self::instance()->get(self::$phpEtcdClientPIDKey);
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
            $pid = (int)self::instance()->get(self::$phpEtcdClientPIDKey);
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
