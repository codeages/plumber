<?php

namespace Codeages\Plumber;

use Codeages\Beanstalk\Client as BeanstalkClient;
use Codeages\Beanstalk\ClientProxy as BeanstalkClientProxy;
use Codeages\Beanstalk\Exception\DeadlineSoonException;

class WorkerProcess
{
    protected $tubeName;

    protected $process;

    protected $container;

    protected $times;

    /**
     * @var BeanstalkClientProxy
     */
    protected $queue;

    const RESERVE_TIMEOUT = 5;

    public function __construct($tubeName, $process, $container)
    {
        $this->tubeName = $tubeName;
        $this->process = $process;
        $this->container = $container;
        $this->times = 0;
    }

    public function run()
    {
        usleep(rand(100, 1000)*1000);
        $process = $this->process;
        $logger = $this->container['logger'];
        $this->connect();

        $executor = $this->createWorkerExecutor($this->tubeName);

        while (true) {
            if (!$this->container['run_flag']->isRuning()) {
                break;
            }

            $job = $this->reserveJob();
            if (empty($job)) {
                continue;
            }

            try {
                $result = $executor->execute($job);
            } catch (\Exception $e) {
                $message = sprintf('tube(%s, #%d): execute job #%d exception, `%s`', $this->tubeName, $process->pid, $job['id'], $e->getMessage());
                $logger->error($message, $job);
                throw $e;
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

    private function connect()
    {
        $tubeName = $this->tubeName;
        $process = $this->process;
        $logger = $this->container['logger'];

        $options = $this->container['server'];
        $options['socket_timeout'] = self::RESERVE_TIMEOUT * 2;

        $this->queue = $queue = new BeanstalkClientProxy(new BeanstalkClient($options), $logger);

        $queue->connect();
        $queue->watch($tubeName);
        $queue->useTube($tubeName);
        $queue->ignore('default');

        $logger->info("tube({$tubeName}, #{$process->pid}): watching.");

        return true;
    }

    private function createWorkerExecutor($name)
    {
        $class = $this->container['tubes'][$name]['class'];
        $worker = new $class($name, $this->container['tubes'][$name]);
        $worker->setContainer($this->container);

        return $worker;
    }

    private function reserveJob()
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->container['logger'];
        $process = $this->process;

        if ($this->times % 100 === 0) {
            $logger->info("tube({$tubeName}, #{$process->pid}): reserving {$this->times} times.");
        }

        $job = false;
        try {
            $job = $queue->reserve(self::RESERVE_TIMEOUT);
        } catch (DeadlineSoonException $e) {
            $logger->info("tube({$tubeName}, #{$process->pid}): reserve job is deadline soon, sleep 2 seconds.");
            sleep(2);
        } catch (\Exception $e) {
            $logger->error('TubeListernerException:'.$e->getMessage());
            $this->process->exit(1);
        }

        ++$this->times;

        if (!$job) {
            return;
        }

        $job['body'] = json_decode($job['body'], true);
        $logger->info("tube({$tubeName}, #{$process->pid}): job #{$job['id']} reserved.", $job);

        return $job;
    }

    private function finishJob($job, $result)
    {
        $tubeName = $this->tubeName;
        $queue = $this->queue;
        $logger = $this->container['logger'];
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
        $logger = $this->container['logger'];
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
        $logger = $this->container['logger'];
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

        $logger->notice("tube({$tubeName}, #{$process->pid}): job #{$job['id']} buried.");
    }
}
