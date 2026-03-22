<?php

declare(strict_types=1);

namespace WsFramework\Process\DefaultProcess;

interface WorkerInterface
{
    /**
     * Функция для определения стартовых параметров и таймеров
     * @return callable
     */
    public static function onWorkerStart(): callable;

    /**
     * Функция подключения клиента к серверу
     * @return callable
     */
    public static function onConnect(): callable;

    /**
     * @return callable
     */
    public static function onMessage(): callable;

    /**
     * @return callable
     */
    public static function onClose(): callable;
}
