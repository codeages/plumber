<?php

namespace Codeages\Plumber;

use Codeages\Plumber\IWorker;
use Codeages\Plumber\Logger;
use Codeages\Plumber\ListenerStats;

use swoole_table;
use swoole_process;

class Plumber
{
    private $server;

    private $config;

    protected $logger;

    protected $output;

    protected $pidManager;

    protected $stats;

    protected $workers;

    protected $state;

    public function __construct($config)
    {
        $this->config = $config;
        $this->pidManager = new PidManager($this->config['pid_path']);
    }

    public function main($op)
    {
        $this->{$op}();
    }

    protected function start()
    {
         if ($this->pidManager->get()) {
            echo "ERROR: plumber is already running.\n";
            return;
         }


        echo "plumber started.\n";

        if ($this->config['daemonize']) {
            swoole_process::daemon();
        }

        $this->logger = new Logger(['log_path' => $this->config['log_path']]);
        $this->output = new Logger(['log_path' => $this->config['output_path']]);

        $this->logger->info('plumber starting...');
        
        $this->stats = $stats = $this->createListenerStats();

        swoole_set_process_name('plumber: master');
        $this->workers = $this->createWorkers($stats);
        $this->registerSignal();

        $this->pidManager->save(posix_getpid());

        swoole_timer_tick(1000, function($timerId) {
            $statses = $this->stats->getAll();
            foreach ($statses as $pid => $s) {
                if ( ($s['last_update'] + $this->config['reserve_timeout'] + $this->config['execute_timeout']) > time()) {
                    continue;
                }
                if (!$s['timeout']) {
                    $this->logger->notice("process #{$pid} last upadte at ". date('Y-m-d H:i:s') . ', it is timeout.', $s);
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
            return ;
        }

        echo "plumber is stoping....";
        exec("kill -15 {$pid}");
        while(1) {
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
        foreach ($this->config['tubes'] as $tubeName => $tubeConfig) {
            $size += $tubeConfig['worker_num'];
        }
        return new ListenerStats($size, $this->logger);
    }

    /**
     * 创建队列的监听器
     */
    private function createWorkers($stats)
    {
        $workers = [];
        foreach ($this->config['tubes'] as $tubeName => $tubeConfig) {
            for($i=0; $i<$tubeConfig['worker_num']; $i++) {
                $worker = new \swoole_process($this->createTubeLoop($tubeName, $stats), true);
                $worker->start();

                swoole_event_add($worker->pipe, function($pipe) use ($worker) {
                    $recv = $worker->read();
                    $this->output->info($recv);
                    echo "recv:" . $recv . " {$pipe} " .  "\n";
                });

                $workers[$worker->pid] = $worker;
            }
        }

        return $workers;
    }

    /**
     * 创建队列处理Loop
     */
    private function createTubeLoop($tubeName, $stats)
    {
        return function($process) use ($tubeName, $stats) {
            $process->name("plumber: tube `{$tubeName}` task worker");

            $listener = new TubeListener($tubeName, $process, $this->config, $this->logger, $stats);
            $listener->connect();

            $beanstalk = $listener->getQueue();

            $listener->loop();
        };
    }

    private function registerSignal()
    {
        swoole_process::signal(SIGCHLD, function() {
            while(1) {
                $ret = swoole_process::wait(false);
                if (!$ret) {
                    break;
                }
                $this->logger->info("process #{$ret['pid']} exited.", $ret);
                unset($this->workers[$ret['pid']]);
                $this->stats->remove($ret['pid']);
            }
        });

        $softkill = function($signo) {
            if ($this->state == 'stoping') {
                return ;
            }
            $this->state = 'stoping';
            $this->logger->info("plumber is stoping....");

            $this->stats->stop();

            // 确保worker进程都退出后，再退出主进程
            swoole_timer_tick(1000, function($timerId) {
                if (!empty($this->workers)) {
                    return ;
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