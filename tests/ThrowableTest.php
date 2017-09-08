<?php

use PHPUnit\Framework\TestCase;
use Codeages\Plumber\ForwardWorker;
use Pimple\Container;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Codeages\Plumber\IWorker;


class WorkerProcessTest extends TestCase
{
    /**
     * Can this test run in php 5 ?
     */
    public function test_ThrowableException()
    {
        try {
            throw new \RuntimeException("some exception");
        } catch (\Exception $e) {

        } catch (\Throwable $e) {

        }

        $this->assertTrue(true);
    }
}