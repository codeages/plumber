<?php
namespace Codeages\Framework\Test;

use Codeages\Framework\Test\Example\Kernel;

class BeanstlkClientTest extends \PHPUnit_Framework_TestCase
{

    public function testGetExample()
    {
        $example = $this->kernel()->dao('ExampleDao')->getExample(1);
    }

    protected function kernel()
    {
        return Kernel::instance();
    }
}