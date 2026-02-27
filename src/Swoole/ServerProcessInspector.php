<?php

namespace Laravel\Octane\Swoole;

use Laravel\Octane\Contracts\ServerProcessInspector as ServerProcessInspectorContract;
use Laravel\Octane\Exec;

class ServerProcessInspector implements ServerProcessInspectorContract
{
    public function __construct(
        protected SignalDispatcher $dispatcher,
        protected ServerStateFile $serverStateFile,
        protected Exec $exec,
    ) {
    }

    /**
     * Determine if the Swoole server process is running.
     */
    public function serverIsRunning(): bool
    {
        [
            'masterProcessId' => $masterProcessId,
            'managerProcessId' => $managerProcessId
        ] = $this->serverStateFile->read();

        return $managerProcessId
                ? $masterProcessId && $managerProcessId && $this->dispatcher->canCommunicateWith((int) $managerProcessId)
                : $masterProcessId && $this->dispatcher->canCommunicateWith((int) $masterProcessId);
    }

    /**
     * Reload the Swoole workers.
     */
    public function reloadServer(): void
    {
        [
            'masterProcessId' => $masterProcessId,
        ] = $this->serverStateFile->read();

        $this->dispatcher->signal((int) $masterProcessId, SIGUSR1);
    }

    /**
     * Stop the Swoole server.
     */
    public function stopServer(): bool
    {
        [
            'masterProcessId' => $masterProcessId,
            'managerProcessId' => $managerProcessId
        ] = $this->serverStateFile->read();

        // In SWOOLE_BASE mode, there is no manager process (pid=0).
        // Workers are direct children of the master process.
        $parentPid = $managerProcessId ?: $masterProcessId;
        $workerProcessIds = $parentPid ? $this->exec->run('pgrep -P '.$parentPid) : [];

        $pidsToKill = array_filter(
            [$masterProcessId, $managerProcessId, ...$workerProcessIds],
            fn ($pid) => (int) $pid > 0
        );

        foreach ($pidsToKill as $processId) {
            $this->dispatcher->signal((int) $processId, SIGKILL);
        }

        return true;
    }
}
