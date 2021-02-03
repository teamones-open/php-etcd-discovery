<?php

namespace teamones\curl;

use teamones\etcd\Discovery;

class Client extends \teamones\http\Client
{
    /**
     * 设置指定服务地址
     * @param string $serverName
     * @return $this
     */
    public function setServerHost($serverName = '')
    {
        $resData = Discovery::instance()->getServerConfigByName($serverName);
        if (!empty($resData) && !empty($resData['server_host'])) {
            // 服务存在
            $this->_host = (string)$resData['server_host'];
            return $this;
        } else {
            throw new \RuntimeException($serverName . ' server not exit', -4000000);
        }
    }
}