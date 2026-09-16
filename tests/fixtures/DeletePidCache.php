<?php

// 仅供 EtcdGoServerCacheTest 的独立 PHP 子进程使用，拒绝测试临时目录之外的目标。
$path = $argv[1] ?? '';
$root = dirname($path, 4);
$temporaryRoot = realpath(sys_get_temp_dir());
if ($temporaryRoot === false || realpath(dirname($root)) !== $temporaryRoot
    || !preg_match('/^etcd-pid-review-[a-f0-9]{16}$/', basename($root))
    || basename($path) !== substr(md5('php_etcd_client_pid'), 3)
) {
    exit(2);
}
exit(unlink($path) ? 0 : 1);
