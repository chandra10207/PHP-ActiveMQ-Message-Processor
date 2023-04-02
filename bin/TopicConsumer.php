<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Broker;

use Exception;
use Stomp\Transport\Frame;



try {
//    $broker = new Broker('localhost', 61613);
//     $broker = new Broker('inventory-amq.sit01.nonprod.a.winning.com.au', 61614);
    $broker = new Broker();
    
} catch (Exception $e) {
    echo "Failed to connect to broker\n";
     echo $e->getMessage();
    exit(1);
}

$selector = "location IN ('201COMM', '201ACOM')";
// print_r($selector);
// $selector = json_encode($selector);
// print_r($selector);
// $selector = { "location IN ('WA-DC-SYD', '201COMM', '201ACOM')"};
// var_dump($broker);
// exit();
$broker->subscribeTopic('inventory_location',$selector);
// $broker->subscribeTopic('orders');

// $message = $broker->read();
// var_dump( $message->getBody());
// if ($message instanceof Frame) {
//     if ($message['type'] === 'terminate') {
//         exit("Received shutdown command\n");
//     }
//     print('RECEIVED');
//     $location_stock = $message->getBody();
//     $broker->ack($message);
// }

// var_dump( $message->getBody());
// exit("Messages Received\n");

    while (true) {
        $message = $broker->read();
        // var_dump( $message->getBody());
        if($message AND $message != ''){
            if ($message instanceof Frame) {
                if ($message['type'] === 'terminate') {
                    // $output->writeln('<comment>Received shutdown command</comment>');
                    // return Command::SUCCESS;
                }
                // $output->writeln('<info>Processed message: ' . $message->getBody() . '</info>');
                var_dump( $message->getBody());
                $broker->ack($message);
            }
            usleep(100000);
            print("Messages received\n");

        }
        else{
            print("No Messages Received\n");
        }
    }

// $broker->unsubscribeTopic();