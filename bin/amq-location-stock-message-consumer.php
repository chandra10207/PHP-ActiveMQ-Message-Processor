<?php
ini_set('max_execution_time', -1);
ini_set('memory_limit','3000M');
date_default_timezone_set ('Australia/Sydney');
require_once __DIR__ . '/../vendor/autoload.php';
use App\Broker;
//use Exception;
use Stomp\Transport\Frame;

//use App\class_amqMessageProcessor;
$pieces = explode("/wp-content", __DIR__);
$root_path = $pieces[0];
$file_path = $root_path.'/wp-content/plugins/plugin_name/message-processor/src/class_amqMessageProcessor.php';
require_once($file_path);

$amqProcessor = new class_amqMessageProcessor();

$aol_sync_setting = get_option('product_sync_setting');
if(!empty($aol_sync_setting) && isset($aol_sync_setting['csp_amq_location_stock_running']) ){
    if($aol_sync_setting['csp_amq_location_stock_running'] == 'yes'){
        $amqProcessor->save_log("Another Consumer Process Already Running ");
        exit;
    }
    else{
        $aol_sync_setting['csp_amq_location_stock_running'] = 'yes';
        update_option( 'product_sync_setting', $aol_sync_setting );
    }
}


$amqProcessor->save_log("###################################");$amqProcessor->save_log("Cron Start.");

$stock_update_hour = intval(AMQ_STOCK_UPDATE_HOUR);
$amqProcessor->save_log("Stock Sync Every ".$stock_update_hour." hour.");
$stock_locations = AMQ_STOCK_LOCATIONS;
//$location_stock_status = [];
$stock_received = [];
if($stock_locations){
    $stock_locations = unserialize($stock_locations);
//    foreach ($stock_locations as $location){
//        $location_stock_status[$location] = 0;
//    }
}

try {
    $broker = new Broker();
} catch (Exception $e) {
    $amqProcessor->save_log("Failed to connect to broker.");$amqProcessor->save_log("Error Message: ".$e->getMessage());
//    update_option( 'csp_amq_location_stock_running', 'no' );
    $aol_sync_setting['csp_amq_location_stock_running'] = 'no';
    update_option( 'product_sync_setting', $aol_sync_setting );
    exit(1);
}
$amqProcessor->save_log("Successfully Connected to Broker!!");

$selector = $broker->getSelector();
$topic = $broker->getTopic();
$amqProcessor->save_log("Selector:". $selector);

try{
    $broker->subscribeTopic( $topic , $selector);
} catch (Exception $e) {
    $amqProcessor->save_log("Topic: inventory_location subscribe Error.");$amqProcessor->save_log("Error Message: ".$e->getMessage());
//    update_option( 'csp_amq_location_stock_running', 'no' );
    $aol_sync_setting['amq_location_stock_running'] = 'no';
    update_option( 'product_sync_setting', $aol_sync_setting );
    exit(1);
}

while (true) {
    try{
        $message = $broker->read();
    } catch (Exception $e) {
        $amqProcessor->save_log("Broker: Read Error.");$amqProcessor->save_log("Error Message: ".$e->getMessage());
        $aol_sync_setting['amq_location_stock_running'] = 'no';
        update_option( 'product_sync_setting', $aol_sync_setting );
        break;
    }

    if($message AND $message != ''){
        if ($message instanceof Frame) {
            if ($message['type'] === 'terminate') {
                $amqProcessor->save_log("Received shutdown command on message.");
            }
            $messageHeader = $message->getHeaders();
            $location = $messageHeader['location'];
            if(!in_array($location,$stock_received)){
                $stock_received[] = $location;
            }
            /*$last_update_hour = $amqProcessor->get_location_stock_updated_hour($location);
            if($last_update_hour < $stock_update_hour){
                $amqProcessor->save_log("Messages ignored for location.".$location. " Last Updated before = ".$last_update_hour." hour");
                $broker->ack($message);
                continue;
            }*/
            $messageBody = $message->getBody();
            $amqProcessor->save_log("Stock Messages received for location.".$location);
//            $messageBody1 = gzuncompress($messageBody);
            $message_JSON_string = gzdecode($messageBody);
//            $messageBodyGZDecodeJSON = json_encode($messageBodyGZDecode);
//            $message_array = json_decode($message_JSON_string);

//            $amqProcessor->stockMessages[$messageHeader] = $message_array;
//            $amqProcessor->saveMessage($messageHeader['location'],$message_array );
            $amqProcessor->save_location_stock_message($location,$message_JSON_string );

            $broker->ack($message);

            if(count($stock_received) == count($stock_locations) ){
                $amqProcessor->save_log("Stock Message Received for ". json_encode($stock_received));
                $amqProcessor->save_log("All Subscribed location ". json_encode($stock_locations));
                $amqProcessor->save_log("Stock Message Received for all locations.");
                break;
            }
//            $location_stock_status[$location] = 1;

        }
        usleep(100000);
    }
    else{
//        echo ("No Messages Received.\n");
//        $amqProcessor->save_log("No Messages Received.");
    }
}

$broker->unsubscribeTopic($topic);

$amqProcessor->save_log("Broker Disconnected.");
$aol_sync_setting['amq_location_stock_running'] = 'no';
update_option( 'product_sync_setting', $aol_sync_setting );

//$amqProcessor->process_stock_message();
$amqProcessor->save_log("Cron End");

require dirname(__FILE__).'/process-location-stock-message.php';

die;
