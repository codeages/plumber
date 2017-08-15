<?php
namespace Codeages\Plumber\Example;

use Codeages\Plumber\IWorker;
use Codeages\Plumber\ContainerAwareTrait;

class Example2Worker implements IWorker
{
    use ContainerAwareTrait;

    public function execute($data)
    {
        // sleep(1);
        // throw new \RuntimeException("exception from example2 worker.");
        
        return array('code' => IWorker::FINISH);
    }

}
