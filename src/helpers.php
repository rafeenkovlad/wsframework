<?php

use Psr\Clock\ClockInterface;

function clock(): ClockInterface
{
    return new class implements ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable;
        }
    };
}

function now(): DateTimeImmutable
{
    return new DateTimeImmutable;
}
