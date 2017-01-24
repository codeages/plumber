<?php

namespace Codeages\Plumber;

use Pimple\Container;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\ErrorHandler;
use swoole_process;

class Plumber
{
    private $server;

    private $container;

    protected $logger;

    protected $output;

    protected $pidManager;

    protected $stats;

    protected $workers;

    protected $state;

    protected $daemon = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->pidManager = new PidManager($this->container['pid_path']);
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
        $this->daemon = $daemon;
        $logger = new Logger('plumber');
        if ($daemon) {
            $logger->pushHandler(new StreamHandler($this->container['log_path']));
        } else {
            $logger->pushHandler(new StreamHandler('php://output'));
        }
        $this->container['logger'] = $this->logger = $logger;
        ErrorHandler::register($logger);

        if ($this->pidManager->get()) {
            echo "ERROR: plumber is already running.\n";
            return;
        }

        echo "plumber started.\n";

        if ($daemon) {
            swoole_process::daemon();
        }

        $this->logger->info('plumber starting...');

        $this->stats = $stats = $this->createListenerStats();

        swoole_set_process_name('plumber: master');
        $this->workers = $this->createWorkers($stats);
        $this->registerSignal();

        $this->pidManager->save(posix_getpid());

        swoole_timer_tick(1000, function ($timerId) {
            $statses = $this->stats->getAll();
            foreach ($statses as $pid => $s) {
                if (($s['last_update'] + $this->container['reserve_timeout'] + $this->container['execute_timeout']) > time()) {
                    continue;
                }
                if (!$s['timeout']) {
                    $this->logger->notice("process #{$pid} last upadte at ".date('Y-m-d H:i:s').', it is timeout.', $s);
                    $this->stats->timeout($pid);
                }
            }
        });
    }

    protected function stop()
    {
        $pid = $this->pidManager->get();
        if (empty($pid)) {
            echo "plumber is not running...\n";

            return;
        }

        echo 'plumber is stoping....';
        exec("kill -15 {$pid}");
        while (1) {
            if ($this->pidManager->get()) {
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

    private function createListenerStats()
    {
        $size = 0;
        foreach ($this->container['tubes'] as $tubeName => $tubeConfig) {
            $size += $tubeConfig['worker_num'];
        }

        return new ListenerStats($size, $this->logger);
    }

    /**
     * 创建队列的监听器.
     */
    private function createWorkers($stats)
    {
        $daemon = $this->daemon;
        $workers = [];
        foreach ($this->container['tubes'] as $tubeName => $tubeConfig) {
            for ($i = 0; $i < $tubeConfig['worker_num']; ++$i) {
                $worker = new \swoole_process($this->createTubeLoop($tubeName, $stats), true);
                $worker->start();

                swoole_event_add($worker->pipe, function ($pipe) use ($worker, $daemon) {
                    if ($daemon) {
                        $this->logger->info(sprintf('recv from pipie %s: %s', $pipe, $worker->read()));
                    } else {
                        echo $worker->read();
                    }
                });

                $workers[$worker->pid] = $worker;
            }
        }

        return $workers;
    }

    /**
     * 创建队列处理Loop.
     */
    private function createTubeLoop($tubeName, $stats)
    {
        return function ($process) use ($tubeName, $stats) {
            $process->name("plumber: tube `{$tubeName}` task worker");

            //@see https://github.com/swoole/swoole-src/issues/183
            try {
                $listener = new TubeListener($tubeName, $process, $this->container, $this->logger, $stats);
                $listener->connect();
                $listener->loop();
            } catch (\Exception $e) {
                $this->logger->error($e);
            }
        };
    }

    private function registerSignal()
    {
        swoole_process::signal(SIGCHLD, function () {
            while (1) {
                $ret = swoole_process::wait(false);
                if (!$ret) {
                    break;
                }
                $this->logger->info("process #{$ret['pid']} exited.", $ret);
                unset($this->workers[$ret['pid']]);
                $this->stats->remove($ret['pid']);
            }
        });

        $softkill = function ($signo) {
            if ($this->state == 'stoping') {
                return;
            }
            $this->state = 'stoping';
            $this->logger->info('plumber is stoping....');

            $this->stats->stop();

            // 确保worker进程都退出后，再退出主进程
            swoole_timer_tick(1000, function ($timerId) {
                if (!empty($this->workers)) {
                    return;
                }
                swoole_timer_clear($timerId);
                $this->pidManager->clear();
                $this->logger->info('plumber is stopped.');
                exit();
            });
        };

        swoole_process::signal(SIGTERM, $softkill);
        swoole_process::signal(SIGINT, $softkill);
    }
}
