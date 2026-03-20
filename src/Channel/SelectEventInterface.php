<?php

namespace WsFramework\Channel;

interface SelectEventInterface
{
    public static function getStatus(): bool;
    public function on(callable $callback, string $method): void;
    public function publish($data,  string $method): void;
}