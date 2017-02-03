<?php

namespace Codeages\Plumber;

use Pimple\Container;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;
use swoole_process;

class Plumber
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var boolean
     */
    protected $daemon;

    protected $workers;

    const ALREADY_RUNNING_ERROR = 1;

    public function __construct(Container $container)
    {
        $container['run_flag'] = new SharedRunFlag();
        $this->locker = new ProcessLocker($container['pid_path']);
        $this->container = $container;
    }

    public function main($op)
    {
        $this->{$op}();
    }

    protected function run()
    {
        $this->start(false);
    }

    protected function start($daemon = true)
    {
        if ($daemon) {
            $this->daemon = true;
            swoole_process::daemon();
        } else {
            $this->daemon = false;
        }

        $locked = $this->locker->lock(posix_getpid());
        if (!$locked) {
            echo "error: plumber is already running(PID: {$this->locker->getId()}).\n";
            exit(self::ALREADY_RUNNING_ERROR);
        }

        swoole_set_process_name('plumber: master');

        $logger = new Logger('plumber');
        if ($daemon) {
            $logger->pushHandler(new StreamHandler($this->container['log_path']));
        } else {
            $logger->pushHandler(new StreamHandler('php://output'));
        }
        ErrorHandler::register($logger);
        $this->container['logger'] = $this->logger = $logger;

        $this->workers = $this->createWorkers();
        $this->registerSignal();

        foreach ($this->workers as $worker) {
            swoole_event_add($worker->pipe, function ($pipe) use ($worker) {
                echo $worker->read();
            });
        }

        $this->container['run_flag']->run();
    }

    protected function stop()
    {
        $pid = $this->locker->getId();
        if (empty($pid)) {
            echo "plumber is not running...\n";
            return;
        }

        echo 'plumber is stoping....';
        exec("kill -15 {$pid}");
        while (1) {
            if ($this->locker->isLocked()) {
                sleep(1);
                continue;
            }

            echo "[OK]\n";
            break;
        }
    }

    protected function restart()
    {
        $this->stop();
        sleep(1);
        $this->start();
    }

    /**
     * 创建队列的监听器.
     */
    private function createWorkers()
    {
        $workers = [];
        foreach ($this->container['tubes'] as $name => $options) {
            for ($i = 0; $i < $options['worker_num']; ++$i) {
                $worker = $this->createWorker($name);
                $workers[$worker->pid] = $worker;
            }
        }

        return $workers;
    }

    private function createWorker($queueName)
    {
        $worker = new \swoole_process(function($process) use($queueName) {
            $process->name("plumber: queue `{$queueName}` worker");
            //@see https://github.com/swoole/swoole-src/issues/183
            try {
                $process = new WorkerProcess($queueName, $process, $this->container);
                $process->run();
            } catch (\Exception $e) {
                $this->logger->error($e);
            }
        }, false, 2);
        $worker->start();

        return $worker;
    }

    private function registerSignal()
    {
        swoole_process::signal(SIGCHLD, function () {
            while (true) {
                $ret = swoole_process::wait(false);
                if (!$ret) {
                    break;
                }

                if ($this->container['run_flag']->isRuning()) {
                    $this->workers[$ret['pid']]->start();
                } else {
                    unset($this->workers[$ret['pid']]);
                    $this->logger->info("process #{$ret['pid']} exited.", $ret);
                    if (empty($this->workers)) {
                        $this->locker->release();
                        swoole_event_exit();
                    }
                }

            }
        });

        $softkill = function ($signo) {
            $this->logger->info('plumber is stoping....');
            $this->container['run_flag']->stop();
        };

        swoole_process::signal(SIGTERM, $softkill);
        swoole_process::signal(SIGINT, $softkill);
    }
}
