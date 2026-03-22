<?php

require __DIR__ . '/vendor/autoload.php';

use Basis\Nats\Client;
use Basis\Nats\Configuration;

$host = 'nats'; // From .env.local
$port = 4222;

$configuration = new Configuration([
    'host' => $host,
    'port' => $port,
]);

try {
    $client = new Client($configuration);
    echo "Connecting to NATS at $host:$port...\n";
    $client->ping();
    echo "Successfully connected to NATS!\n";
} catch (\Exception $e) {
    echo "Failed to connect to NATS: " . $e->getMessage() . "\n";
}
