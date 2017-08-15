<?php

use PHPUnit\Framework\TestCase;
use Codeages\Plumber\ForwardWorker;
use Pimple\Container;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Codeages\Plumber\IWorker;

class ForwardWorkerTest extends TestCase
{
    public function testExecute_ReturnFinish()
    {
        $container = $this->createContainer();
        $worker = $this->createWorker();
        $worker->setContainer($container);

        $job = [
            'id' => 1,
            'body' => [
                'hello' => 'world',
            ]
        ];

        $executed = $worker->execute($job);
        $this->assertEquals(IWorker::FINISH, $executed);
    }

    public function testExecute_ReturnRetry()
    {
        $container = $this->createContainer();
        $worker = $this->createWorker();
        $worker->setContainer($container);

        $job = [
            'id' => 1,
            'body' => [
                'hello' => 'world',
            ],
        ];

        $executed = $worker->execute($job);
        $this->assertEquals(IWorker::FINISH, $executed);
    }

    protected function createWorker($options = [])
    {
        $options = array_merge($options, [
            'destination' => [
                'host' => 'localhost',
                'port' => 11300,
                'tubeName' => 'test_forward_tube',
            ]
        ]);
        $worker = new ForwardWorker('test_tube', $options);

        return $worker;
    }

    protected function createContainer()
    {
        $container = new Container();
        $container['logger'] = function () {
            $logger = new Logger('plumber');
            $logger->pushHandler(new TestHandler());
            return $logger;
        };

        return $container;
    }
}