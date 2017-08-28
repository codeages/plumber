<?php

namespace Codeages\Plumber;

use swoole_process;
use Psr\Log\LoggerInterface;
use Pimple\Container;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;
use Codeages\RateLimiter\RateLimiter;

class Plumber
{
    /**
     * @var Container
     */
    protected $container;

    protected $configFilePath;

    /**
     * @var bool
     */
    protected $daemon;

    protected $workers;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var RateLimiter
     */
    protected $limiter;

    const ALREADY_RUNNING_ERROR = 1;

    const LOCK_PROCESS_ERROR = 2;

    public function __construct(Container $container, $configFilePath)
    {
        $container['run_flag'] = new SharedRunFlag();
        $this->locker = new ProcessLocker($container['pid_path']);
        $this->limiter = new RateLimiter(
            'process_recreate',
            10,
            600,
            new \Codeages\RateLimiter\Storage\ArrayStorage()
        );
        $this->container = $container;
        $this->configFilePath = $configFilePath;
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
        $locked = $this->locker->isLocked();
        if ($locked) {
            echo "error: plumber is already running(PID: {$this->locker->getId()}).\n";
            exit(self::ALREADY_RUNNING_ERROR);
        }

        if ($daemon) {
            $this->daemon = true;
            swoole_process::daemon(true, false);
        } else {
            $this->daemon = false;
        }

        $locked = $this->locker->lock(posix_getpid());
        if (!$locked) {
            echo 'error: lock process error.';
            exit(self::LOCK_PROCESS_ERROR);
        }

        if (isset($this->container['app_name'])) {
            swoole_set_process_name(sprintf('plumber: [%s] master (%s)', $this->container['app_name'], $this->configFilePath));
        } else {
            swoole_set_process_name(sprintf('plumber: master (%s)', $this->configFilePath));
        }


        $logger = new Logger('plumber');
        if ($daemon) {
            $logger->pushHandler(new StreamHandler($this->container['log_path']));
        } else {
            $logger->pushHandler(new StreamHandler('php://output'));
        }
        ErrorHandler::register($logger);
        $this->container['logger'] = $this->logger = $logger;

        $this->workers = $this->startWorkers();
        $this->registerSignal();

        foreach ($this->workers as $worker) {
            swoole_event_add($worker['process']->pipe, function ($pipe) use ($worker, $logger) {
                $logger->info('read from worker:'.$worker['process']->read());
            });
        }

        $this->container['run_flag']->run();

        $logger->info('started.');
    }

    /**
     * @todo
     * 此方法有逻辑缺陷：
     *   如plumber进程异常退出后，pid文件并不会被清除。
     *   当系统重启后，此时pid文件中所指示的pid可能为其他程序的进程，如果这时执行stop操作，存在可能把其他程序进程kill掉的风险。
     */
    protected function stop()
    {
        $pid = $this->locker->getId();
        if (empty($pid)) {
            echo "plumber is not running.\n";

            return;
        }

        echo 'plumber is stoping...';
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
    private function startWorkers()
    {
        $workers = [];
        foreach ($this->container['tubes'] as $name => $options) {
            for ($i = 0; $i < $options['worker_num']; ++$i) {
                $worker = $this->startWorker($name);
                $workers[$worker->pid] = array(
                    'tube' => $name,
                    'process' => $worker,
                );
            }
        }

        return $workers;
    }

    private function startWorker($queueName)
    {
        $worker = new \swoole_process(function ($process) use ($queueName) {
            if (isset($this->container['app_name'])) {
                $process->name("plumber: [{$this->container['app_name']}] queue `{$queueName}` worker");
            } else {
                $process->name("plumber: queue `{$queueName}` worker");
            }
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
                    $worker = $this->workers[$ret['pid']];
                    unset($this->workers[$ret['pid']]);

                    $remainTimes = $this->limiter->check($worker['tube']);
                    if ($remainTimes > 0) {
                        $newPid = $worker['process']->start();
                        $this->workers[$newPid] = $worker;
                        $this->logger->notice("tube {$worker['tube']} process #{$ret['pid']} exited, #{$newPid} is recreated, remain {$remainTimes} recreated times. .", $ret);
                    } else {
                        $this->logger->notice("tube {$worker['tube']} process #{$ret['pid']} exited, reached max recreated times.", $ret);
                    }
                } else {
                    unset($this->workers[$ret['pid']]);
                    $this->logger->info("process #{$ret['pid']} exited.", $ret);
                }

                if (empty($this->workers)) {
                    $this->locker->release();
                    $this->logger->info('stoped.');
                    swoole_event_exit();
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
