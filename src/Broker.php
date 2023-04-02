<?php

namespace App;

use Stomp\Client;
use Stomp\Exception\ConnectionException;
use Stomp\Network\Connection;
use Stomp\Network\Observer\HeartbeatEmitter;
use \Stomp\Network\Observer\ServerAliveObserver;
use Stomp\StatefulStomp;
use Stomp\Transport\Frame;
use Stomp\Transport\Message;

class Broker
{
    // The internal Stomp client
    // private StatefulStomp $client;
    private $client;
    // A list of subscriptions held by this broker
    // private array $subscriptions = [];
    private $subscriptions = [];
    private $host;
    private $port;
    private $brokerUri;
    private $username;
    private $password;
    private $selector;
    private $topic;
    private $path = '';
    private $log_path = '';
    private $log_filepath = '';

    /**
     * For our constructor, we'll pass in the hostname (or IP address) and port number.
     * @throws ConnectionException|\Stomp\Exception\StompException
     */
    public function __construct()
    {
//        $this->set_logfile();
//        $this->load_wp();
        $this->load_amq_config();
        $connection = new Connection($this->host . ':' . $this->port);
        $client = new Client($connection);
        $client->setLogin($this->username, $this->password);
        
        // Once we've created the Stomp connection and client, we will add a heartbeat
        // to periodically let ActiveMQ know our connection is alive and healthy.

//        Optional so commented temporarily to check connection broken issue
//        $client->setHeartbeat(500);

//        $client->setHeartbeat(0, 2000);
//        $client->getConnection()->getObservers()->addObserver(new ServerAliveObserver());
        $connection->setReadTimeout(0, 250000);
        // We add a HeartBeatEmitter and attach it to the connection to automatically send these signals.
        $emitter = new HeartbeatEmitter($client->getConnection());
        $client->getConnection()->getObservers()->addObserver($emitter);
        // Lastly, we create our internal Stomp client which will be used in our methods to interact with ActiveMQ.
        $this->client = new StatefulStomp($client);
        $client->connect();
    }


    private function load_amq_config(): void {
        $this->host = AMQ_HOST;
        $this->port = AMQ_PORT;
        $this->brokerUri = AMQ_HOST . ':' . AMQ_PORT;
        $this->username = AMQ_USERNAME;
        $this->password = AMQ_PASSWORD;
//        $this->selector = AMQ_LOCATION_SELECTOR;
        $stock_locations = AMQ_STOCK_LOCATIONS;
        $stock_locations = unserialize($stock_locations);
        $stock_locations_string = implode("','",$stock_locations);
        $location_selector = "location IN ('".$stock_locations_string."')";
        $this->selector = strval($location_selector);
        $this->topic = AMQ_TOPIC;
    }

    public function getSelector(){
        return $this->selector;
    }

    public function getTopic(){
        return $this->topic;
    }


    public function sendQueue(string $queueName, string $message, array $headers = []): bool
    {
        $destination = '/queue/' . $queueName;
        return $this->client->send($destination, new Message($message, $headers + ['persistent' => 'true']));
    }

    public function sendTopic(string $topicName, string $message, array $headers = []): bool
    {
        $destination = '/topic/' . $topicName;
        return $this->client->send($destination, new Message($message, $headers + ['persistent' => 'true']));
    }

    public function subscribeQueue(string $queueName, ?string $selector = null): void
    {
        $destination = '/queue/' . $queueName;
        $this->subscriptions[$destination] = $this->client->subscribe($destination, $selector, 'client-individual');
    }

    public function subscribeTopic(string $topicName, ?string $selector = null): void
    {
        $destination = '/topic/' . $topicName;
        $this->subscriptions[$destination] = $this->client->subscribe($destination, $selector, 'client-individual');
    }

    public function unsubscribeQueue(?string $queueName = null): void
    {
        if ($queueName) {
            $destination = '/queue/' . $queueName;
            if (isset($this->subscriptions[$destination])) {
                $this->client->unsubscribe($this->subscriptions[$destination]);
            }
        } else {
            $this->client->unsubscribe();
        }
    }

    public function unsubscribeTopic(?string $topicName = null): void
    {
        if ($topicName) {
            $destination = '/topic/' . $topicName;
            if (isset($this->subscriptions[$destination])) {
                $this->client->unsubscribe($this->subscriptions[$destination]);
            }
        } else {
            $this->client->unsubscribe();
        }
    }

    public function read(): ?Frame
    {
        return ($frame = $this->client->read()) ? $frame : null;
    }

    public function ack(Frame $message): void
    {
        $this->client->ack($message);
    }

    public function nack(Frame $message): void
    {
        $this->client->nack($message);
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
        $this->log_path = $this->path . 'wp-content/uploads/amq-process-log/';
        if ( is_dir( $this->log_path ) === false ) {
            mkdir( $this->log_path, 0777, true );
        }
        $this->log_filepath = $this->log_path . gethostname() . "-" . 'amq_process_' . $curdate . ".log";
    }

    public function save_log( $msg ) {
        error_log( '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", 3, $this->log_filepath );
    }
}