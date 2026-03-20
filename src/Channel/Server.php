<?php

namespace WsFramework\Channel;

class Server extends \Channel\Server
{
    public function __construct(string $ip = '0.0.0.0', int $port = 2206, ?string $processName = null)
    {
        parent::__construct($ip, $port);
        if ($processName !== null) {
            $this->_worker->name = $processName;
        }
    }
}
