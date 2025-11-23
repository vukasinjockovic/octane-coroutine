<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Stream;
use Laravel\Octane\Swoole\SwooleClient;
use Laravel\Octane\Swoole\SwooleExtension;
use Laravel\Octane\Swoole\WorkerState;
use Laravel\Octane\Worker;
use Swoole\Coroutine\Channel;
use Swoole\Http\Server;
use Throwable;

class OnWorkerStart
{
    public function __construct(
        protected SwooleExtension $extension,
        protected $basePath,
        protected array $serverState,
        protected WorkerState $workerState,
        protected bool $shouldSetProcessName = true
    ) {
    }

    /**
     * Handle the "workerstart" Swoole event.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    public function __invoke($server, int $workerId)
    {
        // Log detailed worker start info with actual Swoole settings
        $maxRequest = $server->setting['max_request'] ?? 'not set';
        $reloadAsync = isset($server->setting['reload_async']) && $server->setting['reload_async'] ? 'true' : 'false';
        $workerNum = $server->setting['worker_num'] ?? 'unknown';
        $isTaskWorker = $workerId >= ($workerNum);
        
        $workerType = $isTaskWorker ? 'TASK WORKER' : 'WORKER';
        error_log("🚀 {$workerType} #{$workerId} STARTING (max_request: {$maxRequest}, reload_async: {$reloadAsync})");
        
        // Enable coroutine hooks to make blocking functions (sleep, file_get_contents, etc.) coroutine-safe
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        
        if ($this->shouldClearOpcodeCache()) {
            $this->clearOpcodeCache();
        }

        $this->workerState->server = $server;
        $this->workerState->workerId = $workerId;
        $this->workerState->workerPid = posix_getpid();
        $this->workerState->worker = $this->bootWorker($server);

        $this->dispatchServerTickTaskEverySecond($server);
        $this->streamRequestsToConsole($server);

        if ($this->shouldSetProcessName) {
            $isTaskWorker = $workerId >= $server->setting['worker_num'];

            $this->extension->setProcessName(
                $this->serverState['appName'],
                $isTaskWorker ? 'task worker process' : 'worker process',
            );
        }
        
        error_log("✅ {$workerType} #{$workerId} (PID: {$this->workerState->workerPid}) initialized and ready!");
    }

    /**
     * Boot the Octane worker and application.
     *
     * @param  \Swoole\Http\Server  $server
     * @return \Laravel\Octane\Worker|null
     */
    protected function bootWorker($server)
    {
        try {
            $poolConfig = $this->serverState['octaneConfig']['swoole']['pool'] ?? [];
            $poolSize = $poolConfig['size'] ?? 256;
            $minSize = $poolConfig['min_size'] ?? 1;
            $maxSize = $poolConfig['max_size'] ?? 1000;

            $poolSize = max($minSize, min($maxSize, $poolSize));

            error_log("🏊 Creating worker pool with size: {$poolSize} (min: {$minSize}, max: {$maxSize})");

            $this->workerState->clientPool = new Channel($poolSize);

            // Create pool of Workers, each with its own Application instance
            for ($i = 0; $i < $poolSize; $i++) {
                // CRITICAL FIX: Clear Facade resolved instances to prevent state leaks (e.g. Breadcrumbs)
                \Illuminate\Support\Facades\Facade::clearResolvedInstances();
                
                $worker = new Worker(
                    new ApplicationFactory($this->basePath),
                    new SwooleClient
                );
                
                $worker->boot([
                    'octane.cacheTable' => $this->workerState->cacheTable,
                    Server::class => $server,
                    WorkerState::class => $this->workerState,
                ]);
                
                $this->workerState->clientPool->push($worker);
            }

            // Keep the first worker as the default for backward compatibility
            $this->workerState->worker = $this->workerState->clientPool->pop();
            $this->workerState->clientPool->push($this->workerState->worker);
            $this->workerState->client = $this->workerState->worker->getClient() ?? new SwooleClient;

            error_log("✅ Worker pool created successfully with {$poolSize} instances");

            return $this->workerState->worker;
        } catch (Throwable $e) {
            Stream::shutdown($e);

            $server->shutdown();
        }
    }

    /**
     * Start the Octane server tick to dispatch the tick task every second.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    protected function dispatchServerTickTaskEverySecond($server)
    {
        // ...
    }

    /**
     * Register the request handled listener that will output request information per request.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    protected function streamRequestsToConsole($server)
    {
        $this->workerState->worker->onRequestHandled(function ($request, $response, $sandbox) {
            if (! $sandbox->environment('local', 'testing')) {
                return;
            }

            Stream::request(
                $request->getMethod(),
                $request->fullUrl(),
                $response->getStatusCode(),
                (microtime(true) - $this->workerState->lastRequestTime) * 1000,
            );
        });
    }

    /**
     * Determine if the opcode cache should be cleared.
     *
     * @return bool
     */
    protected function shouldClearOpcodeCache()
    {
        return $this->serverState['octaneConfig']['swoole']['clear_opcache'] ?? true;
    }

    /**
     * Clear the APCu and Opcache caches.
     *
     * @return void
     */
    protected function clearOpcodeCache()
    {
        foreach (['apcu_clear_cache', 'opcache_reset'] as $function) {
            if (function_exists($function)) {
                $function();
            }
        }
    }
}
