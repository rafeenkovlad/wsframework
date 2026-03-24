<?php

declare(strict_types=1);

namespace WsFramework\UseCase;

use WsFramework\Dto\DataTransferObject;
use WsFramework\Dto\UseCase\JobKVDTO;

class CleanupJobDirectoryUseCase extends AbstractUseCase
{
    /**
     * @param JobKVDTO $DTO
     */
    public static function handle(DataTransferObject $DTO, ...$args): bool
    {
        $filesDirectory = $_ENV['FFMPEG_FILES_DIRECTORY'] ?? '/var/www/html/public/files/';
        $jobDir = $filesDirectory . 'tmp_jobs/' . $DTO->jobId . '/';
        $localHlsDir = $DTO->ffmpegJob?->localHlsDir;

        $dirsToClean = array_unique(array_filter(
            [$localHlsDir, $jobDir],
            fn($d) => $d && is_dir($d),
        ));

        foreach ($dirsToClean as $dir) {
            foreach (glob($dir . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }

        if (is_dir($jobDir) && count(scandir($jobDir)) === 2) {
            rmdir($jobDir);
        }

        return true;
    }
}
