<?php

namespace Codeages\Plumber;

use Codeages\Beanstalk\Client as BeanstalkClient;
use Codeages\Beanstalk\ClientProxy as BeanstalkClientProxy;
use Codeages\Beanstalk\Exception\DeadlineSoonException;
use Monolog\ErrorHandler;

class TubeListener
{
    protected $tubeName;

    protected $process;

    protected $queue;

    protected $logger;

    protected $stats;

    protected $times = 0;

    public function __construct($tubeName, $process, $config, $logger, $stats)
    {
        $this->tubeName = $tubeName;
        $this->process = $process;
        $this->config = $config;
        $this->logger = $logger;
        $this->stats = $stats;
    }

    public function connect()
    {
        $tubeName = $this->tubeName;
        $process = $this->process;
        $logger = $this->logger;

        $options = $this->config['message_server'];
        $options['socket_timeout'] = $this->config['reserve_timeout'] * 2;

        $queue = new BeanstalkClient($options);
        $queue = new BeanstalkClientProxy($queue, $logger);
        $this->queue = $queue;

        $queue->connect();
        $queue->watch($tubeName);
        $queue->useTube($tubeName);
        $queue->ignore('default');

        $logger->info("tube({$tubeName}, #{$process->pid}): watching.");

        return true;
    }

    public function loop()
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->logger;
        $process = $this->process;
        $worker = $this->createQueueWorker($tubeName);

        while(true) {
            $this->stats->touch($tubeName, $process->pid, false, 0);
            $stoping = $this->stats->isStoping();

            if ($stoping) {
                $this->logger->info("process #{$process->pid} is exiting.");
                $process->exit(1);
                break;
            }

            $job = $this->reserveJob();
            if (empty($job)) {
                continue;
            }

            try {
                $result = $worker->execute($job);
                $this->stats->touch($tubeName, $process->pid, false, 0);
            } catch(\Exception $e) {
                $message = sprintf('tube({$tubeName}, #%d): execute job #%d exception, `%s`', $process->pid, $job['id'], $e->getMessage());
                $logger->error($message, $job);
                continue;
            }

            $code = is_array($result) ? $result['code'] : $result;

            switch ($code) {
                case IWorker::FINISH:
                    $this->finishJob($job, $result);
                    break;
                case IWorker::RETRY:
                    $this->retryJob($job, $result);
                    break;
                case IWorker::BURY:
                    $this->buryJob($job, $result);
                    break;
                default:
                    break;
            }
        }
    }

    private function reserveJob()
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->logger;
        $process = $this->process;

        if ($this->times % 10 === 0) {
            $logger->info("tube({$tubeName}, #{$process->pid}): reserving {$this->times} times.");
        }

        $job = false;
        try {
            $job = $queue->reserve($this->config['reserve_timeout']);
        } catch(DeadlineSoonException $e) {
            $logger->info("tube({$tubeName}, #{$process->pid}): reserve job is deadline soon, sleep 2 seconds.");
            sleep(2);
        } catch(\Exception $e) {
            $logger->error('TubeListernerException:' . $e->getMessage());
            $this->process->exit(1);
        }

        $this->times ++;
        $this->stats->touch($tubeName, $process->pid, true, empty($job['id']) ? 0 : $job['id']);

        if (!$job) {
            return null;
        }

        $job['body'] = json_decode($job['body'], true);
        $logger->info("tube({$tubeName}, #{$process->pid}): job #{$job['id']} reserved.", $job);

        return $job;
    }

    private function finishJob($job, $result)
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->logger;
        $process = $this->process;

        $logger->info("tube({$tubeName}, #{$process->pid}): job #{$job['id']} execute finished.");

        $deleted = $queue->delete($job['id']);
        if (!$deleted) {
            $logger->error("tube({$tubeName}, #{$process->pid}): job #{$job['id']} delete failed, in successful executed.", $job);
        }
    }

    private function retryJob($job, $result)
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->logger;
        $process = $this->process;

        $message = $job['body'];
        if (!isset($message['__retry'])) {
            $message['__retry'] = 0;
        } else {
            $message['__retry'] = $message['__retry'] + 1;
        }
        $stats = $queue->statsJob($job['id']);
        if ($stats === false) {
            $logger->error("tube({$tubeName}, #{$process->pid}): job #{$job['id']} get stats failed, in retry executed.", $job);
            return;
        }

        $logger->info("tube({$tubeName}, #{$process->pid}): job #{$job['id']} retry {$message['__retry']} times.");
        $deleted = $queue->delete($job['id']);
        if (!$deleted) {
            $logger->error("tube({$tubeName}, #{$process->pid}): job #{$job['id']} delete failed, in retry executed.", $job);
            return;
        }

        $pri = isset($result['pri']) ? $result['pri'] : $stats['pri'];
        $delay = isset($result['delay']) ? $result['delay'] : $stats['delay'];
        $ttr = isset($result['ttr']) ? $result['ttr'] : $stats['ttr'];

        $puted = $queue->put($pri, $delay, $ttr, json_encode($message));
        if (!$puted) {
            $logger->error("tube({$tubeName}, #{$process->pid}): job #{$job['id']} reput failed, in retry executed.", $job);
            return;
        }

        $logger->info("tube({$tubeName}, #{$process->pid}): job #{$job['id']} reputed, new job id is #{$puted}");

    }

    private function buryJob($job, $result)
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->logger;
        $process = $this->process;

        $stats = $queue->statsJob($job['id']);
        if ($stats === false) {
            $logger->error("tube({$tubeName}, #{$process->pid}): job #{$job['id']} get stats failed, in bury executed.", $job);
            return;
        }

        $pri = isset($result['pri']) ? $result['pri'] : $stats['pri'];
        $burried = $queue->bury($job['id'], $pri);
        if ($burried === false) {
            $logger->error("tube({$tubeName}, #{$process->pid}): job #{$job['id']} bury failed", $job);
            return;
        }

        $logger->info("tube({$tubeName}, #{$process->pid}): job #{$job['id']} buried.");

    }

    private function createQueueWorker($name)
    {
        $class = $this->config['tubes'][$name]['class'];
        $worker = new $class($name, $this->config['tubes'][$name]);
        $worker->setLogger($this->logger);
        return $worker;
    }

    public function getQueue()
    {
        return $this->queue;
    }

}