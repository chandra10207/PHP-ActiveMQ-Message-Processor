<?php
//namespace App;
//require __DIR__ . '/../vendor/autoload.php';

if ( ! class_exists( 'class_amqMessageProcessor' ) ) {
    class class_amqMessageProcessor
    {
        private $path = '';
        private $log_path = '';
        private $log_filepath = '';
        private $stock_process_log_filepath = '';
        private $location_stock_table_name = '';
        public $stockMessages = [];
        public $json_data_path = '';

        public function __construct() {
            $this->set_logfile();
            $this->load_wp();
            $this->hc_init_inventory_location_table();
        }

        /**
         * Create a table to store location stock message payload data
         * @param object $wpdb
         */
        function hc_init_inventory_location_table()
        {
            global $wpdb;
            $this->location_stock_table_name = $wpdb->prefix.'aol_stock_location_message';

            $charset_collate = $wpdb->get_charset_collate();
            if ($wpdb->get_var("show tables like '$this->location_stock_table_name'") !== $this->location_stock_table_name) {
                $sql = "CREATE TABLE IF NOT EXISTS $this->location_stock_table_name (			
				`id` int(11) NOT NULL AUTO_INCREMENT,
				`location` varchar(255) NOT NULL,
				`message_data` longtext NOT NULL,
				`ready_to_process` boolean DEFAULT false,
				`last_updated` datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY id (id) )  $charset_collate;";
                $wpdb->query($sql);
            }
        }

        public function hc_sku_exist($sku, $temp_product_id = '',  $stock_num = 1): bool{
            $product_id = wc_get_product_id_by_sku(  $sku );
            if($product_id == 0){
                return false;
            }
            return true;
        }

        public function save_location_stock_message ($location, $message){

            $write = fopen($this->json_data_path."/stock_message_json_".$location.".json", "w+");
            if ($write === false) {
                $this->save_log("Unable to open file for location.".$location);
            } else {
                fwrite($write, $message);
                fclose($write);
                global $wpdb;
                $table_name = $this->location_stock_table_name;
                date_default_timezone_set( 'Australia/Sydney' );
                $last_updated = date( 'Y-m-d H:i:s' );
                $data = array(
                    'message_data' => '',
                    'location'    => $location,
                    'ready_to_process'    => 1,
                    'last_updated'  => $last_updated
                );
                $result = $wpdb->update( $table_name, $data, array(
                    'location' => $location,
                ) );

                if ($result === FALSE || $result < 1) {
                    $ret = $wpdb->insert($table_name, $data);
                    if($ret > 0){
                        $this->save_log( 'Message data successfully inserted for location: '.$location.' for process');
                    }
                    else{
                        $this->save_log( 'Message insert error for location: '.$location);
                        $this->save_log( 'Error: '.$wpdb->last_error);
                    }
                }
                else{
                    $this->save_log( 'Message data successfully updated for location: '.$location.' for process');
                }
            }
        }

        public function get_location_stock_updated_hour($location_code){
            global $wpdb;
            $table_name = $this->location_stock_table_name;
            $sql            = $wpdb->prepare( "SELECT last_updated 
						FROM  $table_name
						WHERE location = '%s'",$location_code );
            $rowObj = $wpdb->get_row($sql);
            if(!empty($rowObj)){
                date_default_timezone_set( 'Australia/Sydney' );
                $current_time = date( 'Y-m-d H:i:s' );
                $last_updated = $rowObj->last_updated;
                $t1 = strtotime( $current_time );
                $t2 = strtotime(  $last_updated  );
                $diff = $t1 - $t2;
                $hours = intval($diff / ( 60 * 60 ));
            }
            else{
                $stock_update_hour = 0;
                $stock_update_hour = intval(AMQ_STOCK_UPDATE_HOUR);
                $hours = $stock_update_hour + 1;
            }
            return $hours;
        }

        public function hc_set_ready_to_process($location, $ready_to_process = 0){
            global $wpdb;
            $table_name = $this->location_stock_table_name;
            $data = array(
                'ready_to_process'    => $ready_to_process,
            );
            $result = $wpdb->update( $table_name, $data, array(
                'location' => $location,
            ) );

            if($result > 0){
                $this->save_stock_process_log( 'Location: '.$location.' updated with ready for process ='.$ready_to_process);
            }
            else{
                $this->save_stock_process_log( 'Ready for process update error for location: '.$location);
                $this->save_stock_process_log( 'Error: '.$wpdb->last_error);
            }
        }

        public function get_aol_synced_products(){
            $aol_products = array();
            global $wpdb;
                $query = $wpdb->prepare("SELECT p.ID, pm2.meta_value as sku FROM " . $wpdb->posts . " AS p
                        INNER JOIN " . $wpdb->postmeta. " AS pm ON pm.post_id = p.ID
                        INNER JOIN " . $wpdb->postmeta. " AS pm2 ON pm2.post_id = p.ID
                        WHERE p.post_type = 'product' 
                        AND ( pm.meta_key = '_AOL_synced' AND pm.meta_value = %d )
                        AND pm2.meta_key = '_sku'
                        ORDER BY p.post_date DESC",1);
            $aol_synced_products =  $wpdb->get_results($query,ARRAY_A );
//            foreach($aol_synced_products as $product){
//                $sku = get_post_meta( $product['ID'], '_sku', true );
//                if($sku != ''){
//                    $aol_products[$sku] =$product;
//                }
//            }
            return $aol_synced_products;
        }

        public function get_stock_sync_ready_locations(){
            global $wpdb;
            $table_name = $this->location_stock_table_name;
            $record = $wpdb->get_results("SELECT location FROM $table_name WHERE ready_to_process = 1");
            return $record;
        }

        public function get_product_variations_by_condition($condition, $product_id)
        {
            global $wpdb;
            $table_name = $wpdb->prefix.'posts';
            $record = $wpdb->get_row("SELECT $wpdb->postmeta.post_id AS variation_product_id FROM $wpdb->postmeta LEFT JOIN $table_name ON ( $wpdb->posts.ID = $wpdb->postmeta.post_id ) WHERE ($wpdb->postmeta.meta_key = 'attribute_pa_condition' AND $wpdb->postmeta.meta_value='".$condition."') AND $wpdb->posts.post_parent=$product_id");
            return $record;
        }

        public function get_variations_by_state_sku($state, $sku){

            $args = array(
                'post_type' => 'product_variation',
                'post_status' => 'publish',
                'posts_per_page' => '-1'

            );

            $meta_query[] = array(
                'key' => '_available_stock_in_states',
                'value' => $state,
                'compare' => 'LIKE'
            );
    }

        /**
         * Get state by location code .
         **/
        function get_state_by_location_code($location_code)
        {
            //available in state
//            201MAIN", "301WHSE", "401WHSE", "501WHSE", "601WHSE",
// "701WHSE", "801WHSE", "AO-QL-CNS", "AO-QL-TSV", "AO-DC-GLE"

            $NSW_ACT = array( 'WA-CLR-SYD', 'WAC-CLR-SY', 'ACO-CLR-SY', 'AOL-CLR-SY', '201AOCNEW', '201AOCCO',
                '201AOCCN', '201AOCOLD','201GRACON','201MAIN','AO-QL-CNS', 'AO-QL-TSV', 'AO-DC-GLE');

            $VIC = array( 'WAC-CLR-ME', 'ACO-CLR-ME', 'AOL-CLR-ME', '301AOCCO', '301AOCCN', '301AOCNEW',
                '301AOCOLD','301GRACON', '301WHSE' );

            $SA = array( 'ACO-CLR-AD', 'AOL-CLR-AD', '501AOC', '501AOCCO', '501AOCCN', '501AOCNEW',
                '501AOCOLD', '501WHSE' );

            $QLD = array( 'WA-CLR-BRI', 'WAC-CLR-BR', 'ACO-CLR-BR', 'AOL-CLR-BR', '701AOCCO', '701AOCCN',
                '701AOCNEW', '701AOCOLD','701GRACON', '701WHSE' );

            $WA = array( 'WA-CLR-PER', 'WAC-CLR-PE', 'ACO-CLR-PE', 'AOL-CLR-PE', '801AOCCO', '801AOCCN',
                '801AOCNEW', '801AOCOLD','801GRACON', '801WHSE' );

            if(in_array($location_code, $NSW_ACT)){
                return 'NSW,ACT';
            }
            if(in_array($location_code, $QLD)){
                return 'QLD';
            }
            if(in_array($location_code, $WA)){
                return 'WA';
            }
            if(in_array($location_code, $VIC)){
                return 'VIC';
            }
            if(in_array($location_code, $SA)){
                return 'SA';
            }
            return null;
        }

        /**
         * Update Stock by SKU and location code
         * Warehouse locations Ref: https://winninggroup.atlassian.net/wiki/spaces/WEB/pages/3113746671/Add+Warehouse+Locations
         * @param string $location_code
         * @param string $sku
         */
        public function hc_update_stock_by_locationCode_and_sku($location_code, $sku){
            $state = $this->get_state_by_location_code($location_code);
           if(hc_sku_exist($sku)) {
               $product_id = wc_get_product_id_by_sku(  $sku );
               $product_varition_condition = 'brand-new-runout';
               $bnro_variations = get_product_variations_by_condition($product_varition_condition, $product_id);
           }
        }

        public function get_BNR_product_variations_by_parent(){


        }


        public function get_all_BNRO_product_variations_by_location($location, $condition = 'brand-new-runout')
        {
            global $wpdb;
            $query = $wpdb->prepare("SELECT p.ID as variation_id,p.post_parent as parent_id FROM " . $wpdb->posts . " AS p
                        INNER JOIN " . $wpdb->postmeta. " AS pm ON pm.post_id = p.post_parent
                        INNER JOIN " . $wpdb->postmeta. " AS pm2 ON pm2.post_id = p.ID
                        INNER JOIN " . $wpdb->postmeta. " AS pm3 ON pm3.post_id = p.ID
                        WHERE p.post_type = 'product_variation'
                        AND ( pm.meta_key = '_AOL_synced' AND pm.meta_value = %d )
                        AND ( pm2.meta_key = '_location_code' AND pm2.meta_value = %s )
                        AND ( pm3.meta_key = 'attribute_pa_condition' AND pm3.meta_value = %s )
                        ORDER BY p.post_date DESC", 1, $location, $condition );

            $bnr_variations =  $wpdb->get_results($query,ARRAY_A );
            return $bnr_variations;
        }

        public function get_BNRO_product_variations($condition, $product_id, $location)
        {
            global $wpdb;
            $query = $wpdb->prepare("SELECT p.ID FROM " . $wpdb->posts . " AS p
                        INNER JOIN " . $wpdb->postmeta. " AS pm ON pm.post_id = p.ID
                        INNER JOIN " . $wpdb->postmeta. " AS pm2 ON pm2.post_id = p.ID
                        INNER JOIN " . $wpdb->postmeta. " AS pm3 ON pm3.post_id = p.ID
                        WHERE p.post_parent = %d
                        AND ( pm.meta_key = 'attribute_pa_condition' AND pm.meta_value = %s )
                        AND ( pm2.meta_key = '_location_code' AND pm2.meta_value = %s )
                        AND ( pm3.meta_key = '_AOL_synced_variation' AND pm3.meta_value = %d )
                        ORDER BY p.post_date DESC", $product_id, $condition, $location,1);

            $bnr_variations =  $wpdb->get_results($query,ARRAY_A );
            return $bnr_variations;
        }

        public function get_bnro_variation_price($sku){
            global $wpdb;
            $sql            = $wpdb->prepare( "SELECT price,msrp 
						FROM  wp_aol_product_sync
						WHERE sku = '%s'", $sku );
            return $wpdb->get_results( $sql, ARRAY_A );
        }

        public function hc_create_new_bnro_variation($product_id,$product_variation_condition, $location_code){

            $sku = get_post_meta($product_id, '_sku', true );
            $available_in_states = $this->get_state_by_location_code($location_code);
            $bnro_price = $this->get_bnro_variation_price($sku);
            if(!empty($bnro_price)){
                $price = $bnro_price[0]['price'];
                $msrp = $bnro_price[0]['msrp'];
            }
            else{
                $price = '';
                $msrp = '';
            }
            // Store the values to the attribute on the parent post,  without variables
            wp_set_post_terms( $product_id, $product_variation_condition, 'pa_condition', true);
            $product_attributes = get_post_meta($product_id, '_product_attributes',true);
            $product_attributes['pa_condition'] = array(
                'name' => 'pa_condition',
                'value' => '',
                'position' => '0',
                'is_visible' => 1,
                'is_variation' => 1,
                'is_taxonomy' => 1
            );
            // Attach the above array to the new posts meta data key '_product_attributes'
            update_post_meta($product_id, '_product_attributes', $product_attributes);

            $variation_post = array(
                'post_title' => "Variation of $sku",
                'post_content' => '',
                'post_status' => "publish",
                'post_parent' => $product_id,
                'post_type' => "product_variation",
                'post_date' => date('Y-m-d H:i:s'),
                'meta_input' => array(
                    '_visibility' => 'visible',
                    '_regular_price' => $price,
                    '_price' => $price,
                    '_downloadable' => 'no',
                    '_virtual' => 'no',
                    '_backorders' => 'no',
                    'attribute_pa_condition' => $product_variation_condition,
                    '_location_code' => $location_code,
                    '_manage_stock' => 'yes',
                    '_AOL_synced_variation' => 1,
                    '_available_stock_in_states' => $available_in_states,
                ),
            );

            $variation_post_id = wp_insert_post($variation_post, true);

            wc_delete_product_transients($product_id);

            if ( is_wp_error($variation_post_id) )
            {
                $this->save_stock_process_log('Insert error: ' .$variation_post_id->get_error_message());
            }
            else
            {
                $this->save_stock_process_log("Variation created! for SKU = ".$sku." Location code = ".$location_code." & variation ID = ".$variation_post_id);
                // Get an instance of the WC_Product_Variation object
                $variation = new WC_Product_Variation( $variation_post_id );
                $variation->save(); // Save the data
                WC_Product_Variable::sync($product_id);
            }
            return $variation_post_id;
        }

        public function hc_update_bnro_variation_stock($variation_product_id, $location_code, $stock,$sku = '' ){

            $available_in_states = $this->get_state_by_location_code($location_code);
            $stock_status =  ( isset($stock['a']) && $stock['a'] > 0 )  ? 'instock' : 'outofstock';
            $stock_qty =  ( isset($stock['a']) && $stock['a'] > 0 )  ? $stock['a'] : 0;
            update_post_meta($variation_product_id, '_manage_stock', 'yes');
            update_post_meta($variation_product_id, '_stock_status', $stock_status);
            update_post_meta($variation_product_id, '_stock', $stock_qty);
            update_post_meta($variation_product_id, '_location_code', $location_code);
            update_post_meta($variation_product_id, '_available_stock_in_states', $available_in_states);
            $this->save_stock_process_log("Stock updated! Location code = ".$location_code." & variation ID = ".$variation_product_id." SKU = ".$sku);
        }

        public function hc_update_stock($sku, $product, $stock, $location){
            $product_id = $product['ID'];
            $sku = $product['sku'];
            $product_variation_condition = 'brand-new-runout';
            $bnr_variations = $this->get_BNRO_product_variations($product_variation_condition, $product['ID'], $location);
            if(!empty($bnr_variations)){
                foreach ($bnr_variations as $variation){
                    $variation_id = $variation['ID'];
                    $this->save_stock_process_log("Variation Exists Location code = ".$location." & variation ID = ".$variation_id." SKU = ".$sku);
                    $this->hc_update_bnro_variation_stock($variation_id,$location, $stock,$sku );
                }
            }
            else{
                if($stock['a'] > 0){
                    $variation_id = $this->hc_create_new_bnro_variation($product_id,$product_variation_condition,$location);
                    if($variation_id > 0){
                        $this->hc_update_bnro_variation_stock($variation_id,$location, $stock,$sku );
                        update_post_meta($product_id, '_stock_status', 'instock');
                    }
                }
            }
        }

        public function sayHello(){
            echo 'Hello';
        }

        private function load_wp(): void {
            try {
                if ( ! @require_once( $this->path . '/wp-load.php' ) ) {
                    throw new Exception ( 'wp-load.php does not exist' );
                }
            } catch ( Exception $e ) {
                $err_message = $e->getMessage();
                $err_code    = $e->getCode();
                $log         = "WP load Exception  = " . $err_message . " Code : = " . $err_code;
                $this->save_log( $log );
                exit;
            }
        }

        private function set_logfile(): void {
            date_default_timezone_set( 'Australia/Sydney' );
            $curdate        = date( 'Y-m-d' );
            $this->path     = preg_replace( '/wp-content(?!.*wp-content).*/', '', __DIR__ );
            $this->log_path = $this->path . 'wp-content/uploads/message_processor/logs/';
            $this->json_data_path = $this->path . 'wp-content/uploads/message_processor/';
            if ( is_dir( $this->log_path ) === false ) {
                mkdir( $this->log_path, 0777, true );
            }
            if ( is_dir( $this->json_data_path ) === false ) {
                mkdir( $this->json_data_path, 0777, true );
            }
            $this->log_filepath = $this->log_path . gethostname() . "-" . 'amq_process_' . $curdate . ".log";
            $this->stock_process_log_filepath = $this->log_path . gethostname() . "-" . 'stock_process_' . $curdate . ".log";
        }

        public function save_log( $msg ) {
            date_default_timezone_set( 'Australia/Sydney' );
            error_log( '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", 3, $this->log_filepath );
        }

        public function save_stock_process_log( $msg ) {
            date_default_timezone_set( 'Australia/Sydney' );
            error_log( '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", 3, $this->stock_process_log_filepath );
        }

    }
}