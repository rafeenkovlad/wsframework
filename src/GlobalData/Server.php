<?php

namespace WsFramework\GlobalData;

class Server extends \GlobalData\Server
{
    public function __construct(string $ip = '0.0.0.0', int $port = 2207, ?string $processName = null)
    {
        parent::__construct($ip, $port);
        if ($processName !== null) {
            $this->_worker->name = $processName;
        }
    }
}
