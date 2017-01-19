<?php
namespace Codeages\Plumber;

use Pimple\Container;

trait ContainerAwareTrait
{
    protected $container;

    protected $logger;

    public function setContainer(Container $container = null)
    {
        $this->container = $container;
        $this->logger = $container['logger'];
    }
}
