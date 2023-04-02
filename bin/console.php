
<?php


require __DIR__.'/../vendor/autoload.php';

use App\TopicConsumerConsoleApp;

use Symfony\Component\Console\Application;

$application = new Application("ActiveMQ Stomp Invntory Location Test Console", "1.0");
$application->add(new TopicConsumerConsoleApp);
$application->run();