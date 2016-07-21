<?php

return [
    'bootstrap' => __DIR__ . '/bootstrap.php',
    'message_server' => [
        'host' => '127.0.0.1',
        'port' => 11300,
    ],
    'tubes' => [
        'Example1' => ['worker_num' => 2, 'class' => 'Codeages\\Plumber\\Example\\Example1Worker'],
        'Example2' => ['worker_num' => 2, 'class' => 'Codeages\\Plumber\\Example\\Example2Worker'],
        'Example3' => [
            'worker_num' => 2,
            'class' => 'Codeages\Plumber\ForwardWorker',
            'destination' => [
                'host' => 'localhost', // 转发目的(host, port)队列，不能跟当前worker所监听的队列一样。
                'port' => 11301,
                'tubeName' => 'Example2',
            ]
        ],
    ],

    'log_path' => __DIR__ . '/tmp/plumber.log',
    'output_path' =>  __DIR__  . '/tmp/plumber.output.log',
    'pid_path' =>  __DIR__  . '/tmp/plumber.pid',
    'daemonize' => 0,
    'reserve_timeout' => 10,
    'execute_timeout' => 60,
];
