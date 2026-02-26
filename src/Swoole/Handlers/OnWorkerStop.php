<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\Swoole\Coroutine\CoordinatorManager;
use Laravel\Octane\Swoole\Coroutine\Monitor;
use Swoole\Coroutine;

/**
 * OnWorkerStop Handler
 *
 * Handles graceful worker shutdown, ensuring in-flight coroutines
 * complete before the worker exits.
 */
class OnWorkerStop
{
    /**
     * Maximum time to wait for in-flight requests to complete (seconds)
     */
    protected int $maxShutdownWait;

    /**
     * Create a new OnWorkerStop handler
     *
     * @param  int  $maxShutdownWait  Maximum wait time in seconds
     */
    public function __construct(int $maxShutdownWait = 30)
    {
        $this->maxShutdownWait = $maxShutdownWait;
    }

    /**
     * Handle the "workerstop" Swoole event
     *
     * @param  \Swoole\Http\Server  $server
     * @param  int  $workerId
     * @return void
     */
    public function __invoke($server, int $workerId): void
    {
        $workerType = $workerId >= ($server->setting['worker_num'] ?? 0) ? 'TASK WORKER' : 'WORKER';

        // error_log("{$workerType} #{$workerId} beginning graceful shutdown...");

        // Signal that worker is exiting - allows in-flight coroutines to check
        CoordinatorManager::until(CoordinatorManager::WORKER_EXIT)->resume();

        // onWorkerStop may run outside coroutine context (e.g. during
        // max_request restart). Coroutine::sleep() and Channel ops require
        // coroutine context. If we're not in a coroutine, skip the wait loop.
        $inCoroutine = Coroutine::getCid() >= 0;

        if ($inCoroutine) {
            $initialRequests = Monitor::getActiveRequestCount();
            // error_log("{$workerType} #{$workerId} waiting for {$initialRequests} active requests...");

            $waited = 0;
            $checkInterval = 0.1;

            while ($waited < $this->maxShutdownWait) {
                $activeRequests = Monitor::getActiveRequestCount();

                if ($activeRequests === 0) {
                    break;
                }

                Coroutine::sleep($checkInterval);
                $waited += $checkInterval;
            }
        } else {
            // error_log("{$workerType} #{$workerId} shutdown outside coroutine context — skipping wait loop");
        }

        // Clear tracked request coroutines
        Monitor::clearRequestCoroutines();

        // Clear coordinators for this worker
        CoordinatorManager::clearAll();
    }
}
