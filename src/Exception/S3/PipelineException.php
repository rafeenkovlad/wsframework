<?php

namespace WsFramework\Exception\S3;


use WsFramework\Exception\AbstractException;

class PipelineException extends AbstractException
{
    public function __construct(string $pipeline, string $message)
    {
        parent::__construct("Pipeline {$pipeline} Exception: $message");
    }
}