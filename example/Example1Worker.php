<?php

namespace Codeages\Plumber\Example;

use Codeages\Plumber\IWorker;
use Psr\Log\LoggerInterface;

class Example1Worker implements IWorker
{

    public function execute($data)
    {
        echo "I'm example 1 worker.\n";

        // sleep(100);
        return array('code' => IWorker::FINISH);
    }

    public function setLogger(LoggerInterface $logger)
    {

    }

}