<?php

use Pimple\Container;

$options = [
    'server' => [
        'host' => getenv('QUEUE_HOST') ? : '127.0.0.1',
        'port' => getenv('QUEUE_PORT') ? : 11300,
    ],
    'tubes' => [
        'Example1' => ['worker_num' => 1, 'class' => 'Codeages\\Plumber\\Example\\Example1Worker'],
        'Example2' => ['worker_num' => 1, 'class' => 'Codeages\\Plumber\\Example\\Example2Worker'],
        'ExampleForward' => [
            'worker_num' => 1,
            'class' => 'Codeages\Plumber\ForwardWorker',
            'destination' => [
                'host' => 'localhost', // 转发目的(host, port)队列，不能跟当前worker所监听的队列一样。
                'port' => 11301,
                'tubeName' => 'ExampleForward',
            ]
        ],
    ],

    'log_path' => __DIR__ . '/tmp/plumber.log',
    'pid_path' =>  __DIR__  . '/tmp/plumber.pid',
];

$container = new Container($options);
return $container;
