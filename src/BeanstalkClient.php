<?php

namespace Codeages\Plumber;

use Beanstalk\Client;

class BeanstalkClient extends Client
{
    protected $_latestError;

    public function getLatestError()
    {
        $error = $this->_latestError;
        $this->_latestError = null;
        return $error;
    }

    protected function _error($message)
    {
        parent::_error($message);
        $this->_latestError = $message;
    }
}