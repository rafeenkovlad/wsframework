<?php

declare(strict_types=1);

use WsFramework\Config\ENV;
use WsFramework\Channel\FfmpegQueueChannel;
use WsFramework\GlobalData\FfmpegQueueGlobalData;
use WsFramework\Process\DefaultProcess\FfmpegQueueProcess\FfmpegQueueProcess;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

require_once './vendor/autoload.php';
require_once './monitor/file-monitor.php';

define("HOME", dirname(__FILE__) . '/..');
define("TMP", '/var/www/html/tmp');

error_reporting(E_ALL);
TcpConnection::$defaultMaxSendBufferSize = 10485760;
TcpConnection::$defaultMaxPackageSize = 104857600;
Worker::$pidFile = HOME . '/tmp/workerman.pid';

// Initialize environment
ENV::init();

// FFmpeg Queue Process
FfmpegQueueChannel::main();
FfmpegQueueGlobalData::main();
$ffmpegQueue = new FfmpegQueueProcess();
$ffmpegQueue->init();

Worker::runAll();
