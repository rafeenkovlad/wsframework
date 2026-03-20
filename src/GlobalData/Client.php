<?php

namespace WsFramework\GlobalData;

class Client extends \GlobalData\Client
{
    public function __construct($ip = '0.0.0.0', $port = 2207)
    {
        if (preg_match('/^unix:.*/s', $ip)) {
            parent::__construct($ip);
        } else {
            parent::__construct($ip . ':' . $port);
        }
    }
}