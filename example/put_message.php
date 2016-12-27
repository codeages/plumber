#!/usr/bin/env php
<?php

use Codeages\Beanstalk\Client as BeanstalkClient;

require_once __DIR__.'/../vendor/autoload.php';

$config = [];
$config['persistent'] = false;

$beanstalk = new BeanstalkClient($config);

$beanstalk->connect();
$beanstalk->useTube('Example1');

$i=0;

for ($i=0; $i<10; $i++) {
    $message = json_encode(array('id'=>uniqid(md5(gethostname())), 'name' => 'Hello ' . $i));
    $result = $beanstalk->put(
        500, // Give the job a priority of 23.
        0,  // Do not wait to put job into the ready queue.
        60, // Give the job 1 minute to run.
        $message // The job's .body
    );
    echo $message . "\n";
}

$beanstalk->disconnect();
