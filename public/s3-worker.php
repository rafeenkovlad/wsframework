<?php

declare(strict_types=1);

use WsFramework\Config\ENV;
use WsFramework\Process\DefaultProcess\S3DownloadProcess\S3DownloadProcess;
use WsFramework\Process\DefaultProcess\S3UploadProcess\S3UploadProcess;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

require_once './vendor/autoload.php';

define("HOME", dirname(__FILE__) . '/..');
define("TMP", '/var/www/html/tmp');

error_reporting(E_ALL);
TcpConnection::$defaultMaxSendBufferSize = 10485760;
TcpConnection::$defaultMaxPackageSize = 104857600;
Worker::$pidFile = HOME . '/tmp/workerman-s3.pid';

ENV::init();

$download = new S3DownloadProcess();
$download->init();

$upload = new S3UploadProcess();
$upload->init();

Worker::runAll();
