<?php

declare(strict_types=1);

namespace WsFramework\Config;

use Symfony\Component\Dotenv\Dotenv;

class ENV
{
    /**
     * @var Dotenv
     */
    private static Dotenv $env;

    private function __construct()
    {

    }

    /**
     * @return void
     */
    public static function init(): void
    {
        if (!isset(static::$env)) {
            static::$env = new Dotenv();
            static::$env->load('./.env');
        }
    }
}