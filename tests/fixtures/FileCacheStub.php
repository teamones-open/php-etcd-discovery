<?php

namespace Ark\Filecache;

/**
 * 无 vendor 时使用的最小文件缓存替身，保留 ark/filecache 0.1.0 的两次读取边界。
 * 集成测试可通过 ETCD_FILECACHE_SOURCE_DIR 加载真实依赖，跳过此替身。
 */
class FileCache
{
    protected $options;

    public function __construct($options = [])
    {
        $this->options = $options;
    }

    public function getPath($key)
    {
        $hash = md5($key);
        return $this->options['root'] . '/' . $hash[0] . '/' . $hash[1] . '/'
            . $hash[2] . '/' . substr($hash, 3);
    }

    public function set($key, $value, $options = [])
    {
        $path = $this->getPath($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        return file_put_contents($path, "serialize:json\ncompress:0\n\n" . json_encode($value));
    }

    public function getMeta($key)
    {
        $handle = @fopen($this->getPath($key), 'r');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 100);
        fclose($handle);
        $offset = strpos($header, "\n\n");
        return $offset === false ? false : ['_META_SIZE' => $offset + 2];
    }

    public function get($key)
    {
        $meta = $this->getMeta($key);
        if (!$meta) {
            return false;
        }
        $value = file_get_contents($this->getPath($key), false, null, $meta['_META_SIZE']);
        return $value === false ? false : json_decode($value, true);
    }
}
