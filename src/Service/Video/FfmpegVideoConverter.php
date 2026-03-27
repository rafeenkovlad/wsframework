<?php

declare(strict_types=1);

namespace WsFramework\Service\Video;

use FFMpeg\FFProbe\DataMapping\Stream;
use Streaming\FFMpeg as StreamFFmpeg;
use Streaming\Representation;

class FfmpegVideoConverter implements VideoConverterInterface
{
    private readonly array $config;

    public function __construct(
        private readonly string $filesDirectory = '/var/www/html/public/files/',
        private readonly int $maxWidth = 720,
        private readonly int $hlsTime = 10,
        private readonly int $kiloBitrate = 2048,
    ) {
        $this->config = [
            'ffmpeg.binaries' => $_ENV['FFMPEG_BINARY_PATH'] ?? '/usr/local/bin/ffmpeg',
            'ffprobe.binaries' => $_ENV['FFPROBE_BINARY_PATH'] ?? '/usr/local/bin/ffprobe',
            'timeout' => (int) ($_ENV['FFMPEG_TIMEOUT'] ?? 3600),
            'ffmpeg.threads' => (int) ($_ENV['FFMPEG_THREADS'] ?? 0),
        ];
    }

    public function getDimensions(string $filePath): array
    {
        $ffmpeg = StreamFFmpeg::create($this->config);
        $video = $ffmpeg->open($this->filesDirectory . $filePath);
        $stream = $video->getStreams()->videos()->first();

        if (!$stream instanceof Stream) {
            throw new \RuntimeException('Video stream not found in file: ' . $filePath);
        }

        $width = (int) $stream->get('width');
        $height = (int) $stream->get('height');

        $rotation = $this->getStreamRotation($stream);
        if ($rotation === 90 || $rotation === 270) {
            [$width, $height] = [$height, $width];
        }

        $isPortrait = $height > $width;
        $longSide = $isPortrait ? $height : $width;
        $shortSide = $isPortrait ? $width : $height;

        if ($longSide > $this->maxWidth) {
            $shortSide = (int) ($shortSide * ($this->maxWidth / $longSide));
            $longSide = $this->maxWidth;
        }

        if ($longSide % 2 !== 0) {
            $longSide++;
        }
        if ($shortSide % 2 !== 0) {
            $shortSide++;
        }

        return $isPortrait
            ? ['width' => $shortSide, 'height' => $longSide]
            : ['width' => $longSide, 'height' => $shortSide];
    }

    private function getStreamRotation(Stream $stream): int
    {
        $tags = $stream->get('tags');
        if (is_array($tags) && isset($tags['rotate'])) {
            return abs((int) $tags['rotate']);
        }

        $sideData = $stream->get('side_data_list');
        if (is_array($sideData)) {
            foreach ($sideData as $data) {
                if (isset($data['rotation'])) {
                    return abs((int) $data['rotation']);
                }
            }
        }

        return 0;
    }

    public function convertToHls(string $filePath, int $width, int $height): string
    {
        $ffmpeg = StreamFFmpeg::create($this->config);
        $video = $ffmpeg->open($this->filesDirectory . $filePath);

        $video->hls()
            ->setHlsTime((string)$this->hlsTime)
            ->x264()
            ->addRepresentation(
                (new Representation)->setKiloBitrate($this->kiloBitrate)->setResize($width, $height)
            )
            ->save();

        $filePath = preg_replace('/^(.*?)\.(mp4|avi|mov|mkv|flv|wmv|webm|mpeg|mpg|m4v|3gp|ogv)$/six', '$1', $filePath);

        return $filePath . '.m3u8';
    }
}
