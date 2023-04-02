<?php
ini_set('max_execution_time', -1);
ini_set('memory_limit','3000M');
date_default_timezone_set ('Australia/Sydney');
//require_once __DIR__ . '/../vendor/autoload.php';
//use App\class_amqMessageProcessor;

$pieces = explode("/wp-content", __DIR__);
$root_path = $pieces[0];
$message_processor_file_path = $root_path.'/wp-content/plugins/some_plugin/message-processor/src/class_amqMessageProcessor.php';
require_once($message_processor_file_path);

$amqMessageProcessor = new class_amqMessageProcessor();
$amqMessageProcessor->save_stock_process_log("###################################");$amqMessageProcessor->save_stock_process_log("Cron Start.");

$rootPath = preg_replace('/wp-content(?!.*wp-content).*/', '', __DIR__);
$stock_json_dir_path = $rootPath . 'wp-content/uploads/message_processor/';

$stock_ready_locations = $amqMessageProcessor->get_stock_sync_ready_locations();
$amqMessageProcessor->save_stock_process_log("Stock Ready Locations: " . count($stock_ready_locations));
$aol_synced_products = $amqMessageProcessor->get_aol_synced_products();
$amqMessageProcessor->save_stock_process_log("AOL Synced Products Count : " . count($aol_synced_products));

if(!empty($stock_ready_locations)){
    $amqMessageProcessor->save_stock_process_log("Stock ready locations: ".count($stock_ready_locations));
    foreach ($stock_ready_locations as $location_obj){
        $location = $location_obj->location;
        $amqMessageProcessor->save_stock_process_log('------------- '.$location.' --------------' );
        $location_stock_json_file_url = $stock_json_dir_path."stock_message_json_".strval($location).".json";
        if (file_exists($location_stock_json_file_url)) {
            $location_stock_json_data = file_get_contents($location_stock_json_file_url);
            if (!empty($location_stock_json_data)) {
                $location_stock = json_decode($location_stock_json_data, true);
//                $bnr_variations_by_location = $amqMessageProcessor->get_all_BNRO_product_variations_by_location($location);

                if(!empty($aol_synced_products)){
                    foreach($aol_synced_products as $product){
                        $sku = $product['sku'];
                        $found_key = array_search($sku, array_column($location_stock, 'no'));
                        if(!$found_key){
                            $amqMessageProcessor->save_stock_process_log("Stock Data Not Found for SKU: ".$sku.' Location: '.$location );
                            continue;
                        }
                        $sku_stock = $location_stock[$found_key];
                        $amqMessageProcessor->save_stock_process_log("Stock Data for SKU: ".$sku.' Location: '.$location );
                        $amqMessageProcessor->save_stock_process_log( json_encode($sku_stock));
//                        $amqMessageProcessor->stockMessages[$location_obj->location] = $location_stock;
                        $amqMessageProcessor->csp_update_stock($sku, $product, $sku_stock,$location);
                    }
                }
                $amqMessageProcessor->csp_set_ready_to_process($location);
            }
        }
    }
}

$amqMessageProcessor->save_stock_process_log("Cron End");
die;
