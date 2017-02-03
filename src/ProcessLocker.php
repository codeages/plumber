<?php

namespace Codeages\Plumber;

use swoole_process;

class ProcessLocker
{
    private $path;

    public function __construct($path)
    {
        $this->path = $path;
    }

    public function lock($id)
    {
        if ($this->isLocked()) {
            return false;
        }
        $id = intval($id);
        file_put_contents($this->path, $id);
        return true;
    }

    public function isLocked()
    {
        if (!file_exists($this->path)) {
            return false;
        }

        $id = intval(file_get_contents($this->path));
        if (!swoole_process::kill($id, 0)) {
            return false;
        }

        return true;
    }

    public function release()
    {
        if (!file_exists($this->path)) {
            return;
        }

        unlink($this->path);
    }

    public function getId()
    {
        if (!file_exists($this->path)) {
            return false;
        }

        return intval(file_get_contents($this->path));
    }
}
