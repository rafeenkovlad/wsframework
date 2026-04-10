<?php

declare(strict_types=1);

namespace WsFramework\Service\Video;

interface VideoConverterInterface
{
    /**
     * @return array{width: int, height: int}
     */
    public function getDimensions(string $filePath): array;

    /**
     * Конвертирует MP4 в HLS (.m3u8).
     * Возвращает имя выходного файла (с расширением .m3u8).
     */
    public function convertToHls(string $filePath, int $width, int $height): string;

    /**
     * Проверяет, что видео уже сжато (H.264 + длинная сторона ≤ maxWidth).
     */
    public function isAlreadyCompressed(string $filePath): bool;

    /**
     * Нарезает видео в HLS через stream copy (без перекодирования).
     * Возвращает имя выходного файла (с расширением .m3u8).
     */
    public function copyToHls(string $filePath): string;
}
