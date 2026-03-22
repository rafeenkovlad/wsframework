<?php

require __DIR__ . '/vendor/autoload.php';

use Basis\Nats\Client;
use Basis\Nats\Configuration;

$host = 'nats';
$port = 4222;

$configuration = new Configuration([
    'host' => $host,
    'port' => $port,
]);

try {
    $client = new Client($configuration);
    echo "Connecting to NATS at $host:$port...\n";
    $api = $client->getApi();

    $streams = $api->getStreamNames();
    echo "Existing streams: " . implode(', ', $streams) . "\n";

    foreach ($streams as $streamName) {
        $stream = $api->getStream($streamName);
        $info = $stream->getInfo();
        echo "Stream: $streamName (Messages: {$info->state->messages}, Bytes: {$info->state->bytes})\n";

        $consumers = $stream->getConsumerNames();
        if (empty($consumers)) {
            echo "  No consumers.\n";
            continue;
        }
        foreach ($consumers as $consumerName) {
            $consumer = $stream->getConsumer($consumerName);
            $cInfo = $consumer->getInfo();
            echo "  Consumer: $consumerName (Pending: {$cInfo->num_pending}, Ack Pending: {$cInfo->num_ack_pending})\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
