<?php

namespace Codeages\Plumber;

use Psr\Log\LoggerInterface;
use Codeages\Beanstalk\Client as BeanstalkClient;

class ForwardWorker implements IWorker
{
    use ContainerAwareTrait;

    protected $config;
    protected $tubeName;
    protected $delays = array(2, 4, 8);
    protected $destQueue;
    protected $destQueueName;

    public function __construct($tubeName, $config)
    {
        $this->config = $config;
        $this->tubeName = $tubeName;

        $config = $this->config['destination'];
        $config['persistent'] = true;

        $this->destQueueName = isset($this->config['destination']['tubeName']) ? $this->config['destination']['tubeName'] : $this->tubeName;
        $this->destQueue = new BeanstalkClient($config);
        $this->destQueue->connect();
        $this->destQueue->useTube($this->destQueueName);
    }

    public function execute($job)
    {
        try {
            $body = $job['body'];

            $pri = isset($this->config['pri']) ? $this->config['pri'] : 0;
            $delay = isset($this->config['delay']) ? $this->config['delay'] : 0;
            $ttr = isset($this->config['ttr']) ? $this->config['ttr'] : 60;

            if (!isset($body['retry'])) {
                unset($body['retry']);
            }

            $this->destQueue->put($pri, $delay, $ttr, json_encode($body));

            $this->logger->info("put job to host:{$this->config['destination']['host']} port:{$this->config['destination']['port']} tube:{$this->destQueueName} ", $body);

            return IWorker::FINISH;
        } catch (\Exception $e) {
            $body = $job['body'];
            if (!isset($body['retry'])) {
                $retry = 0;
            } else {
                $retry = $body['retry'] + 1;
            }
            if ($retry < 3) {
                $this->logger->error("job #{$job['id']} forwarded error, job is retry.  error message: {$e->getMessage()}", $job);

                return array('code' => IWorker::RETRY, 'delay' => $this->delays[$retry]);
            }
            $this->logger->error("job #{$job['id']} forwarded error, job is burried.  error message: {$e->getMessage()}", $job);

            return IWorker::BURY;
        }
    }
}
