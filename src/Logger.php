<?php

namespace Codeages\Plumber;

use Psr\Log\LoggerInterface;
use swoole_async_write;

class Logger implements LoggerInterface
{
    protected $options;

    public function __construct(array $options)
    {
        if (empty($options['log_path'])) {
            throw new \InvalidArgumentException("Logger construct error: log_path is empty.");
        }

        if (!file_exists($options['log_path'])) {
            $touched = touch($options['log_path']);
            if (!$touched) {
                throw new \RuntimeException("Create Log file error.");
            }
        }

        if (!is_writable($options['log_path'])) {
            throw new \RuntimeException("Log file is not writeable.");
        }

        $this->options = $options;
    }

    public function emergency($message, array $context = array())
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = array())
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = array())
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = array())
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = array())
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = array())
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = array())
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = array())
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = array())
    {
        $content = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ';
        $content .= $message . ' ' . json_encode($context) . "\n";
        file_put_contents($this->options['log_path'], $content, FILE_APPEND);
    }

}