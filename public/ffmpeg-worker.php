<?php

declare(strict_types=1);

use WsFramework\Config\ENV;
use WsFramework\Process\DefaultProcess\FfmpegQueueProcess\FfmpegQueueDLQProcess;
use WsFramework\Process\DefaultProcess\FfmpegQueueProcess\FfmpegQueueProcess;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

require_once './vendor/autoload.php';

define("HOME", dirname(__FILE__) . '/..');
define("TMP", '/var/www/html/tmp');

error_reporting(E_ALL);
TcpConnection::$defaultMaxSendBufferSize = 10485760;
TcpConnection::$defaultMaxPackageSize = 104857600;
Worker::$pidFile = HOME . '/tmp/workerman-ffmpeg.pid';

ENV::init();

$ffmpegConsumer = new FfmpegQueueProcess();
$ffmpegConsumer->init();

$ffmpegConsumer = new FfmpegQueueDLQProcess();
$ffmpegConsumer->init();

Worker::runAll();
