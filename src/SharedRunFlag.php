<?php
namespace Codeages\Plumber;

use swoole_atomic;

class SharedRunFlag
{
    protected $flag;

    public function __construct()
    {
        $this->flag = new swoole_atomic();
    }

    public function run()
    {
        $this->flag->set(1);
    }

    public function stop()
    {
        $this->flag->set(0);
    }

    public function isRuning()
    {
        return $this->flag->get() === 1;
    }
}
