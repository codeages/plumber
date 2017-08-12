<?php

namespace Codeages\Plumber;

use Pimple\Container;

interface IWorker
{
    /**
     * Worker执行返回码：执行成功
     */
    const FINISH = 'finish';

    /**
     * Worker执行返回码：重试
     *   返回可选参数：
     *     * delay: 延迟{delay}秒执行
     *     * pri: 任务优先级
     *     * ttr: 任务执行的超时时间
     *   如未指定可选参数，则沿用原有job的值
     */
    const RETRY = 'retry';

    /**
     * Worker执行返回码：搁置.
     */
    const BURY = 'bury';

    public function execute($data);

    public function setContainer(Container $container = null);
}
