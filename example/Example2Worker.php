<?php
namespace Codeages\Plumber\Example;

use Codeages\Plumber\IWorker;
use Psr\Log\LoggerInterface;

class Example2Worker implements IWorker
{

    public function execute($data)
    {
        echo "I'm example 2 worker.";
        return array('code' => IWorker::FINISH);
    }

    public function setLogger(LoggerInterface $logger)
    {
        
    }

}