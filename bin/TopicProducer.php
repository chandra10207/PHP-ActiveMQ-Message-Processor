<?php

use App\class_amqMessageProcessor;
use App\Broker;
use Exception;
use Stomp\Transport\Frame;
require_once __DIR__ . '/../vendor/autoload.php';
$amqProcessor = new class_amqMessageProcessor();
try {
    $broker = new Broker();
    
} catch (Exception $e) {
    echo "Failed to connect to broker\n";
    echo $e->getMessage();
    exit(1);
}



$message = '[
  {
    "no": "23525325", 
    "m": "2022-01-01 00:00:01", 
    "t": 1, 
    "i": 5, 
    "a": 3 
  },
  {
    "no": "1351351",
    "m": "2021-02-03 00:00:04",
    "t": 4,
    "i": 4,
    "a": 2
  }
]';


$gzdata = gzencode(json_encode($message), 9);
$compressed = gzcompress($message, 9);
$broker->sendTopic('inventory_location', $compressed,
    [
        'location' => 'location_code',
    ]);


exit("Messages Sent\n");

