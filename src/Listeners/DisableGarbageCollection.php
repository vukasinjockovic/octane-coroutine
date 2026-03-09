<?php

namespace Laravel\Octane\Listeners;

class DisableGarbageCollection
{
    /**
     * Disable PHP's internal cyclic garbage collector on worker boot.
     *
     * This is NOT the same as Octane's CollectGarbage listener. CollectGarbage
     * calls gc_collect_cycles() probabilistically after requests (controlled by
     * the octane.garbage config). This listener disables PHP's own internal GC
     * mechanism which triggers automatically when the gc_root buffer fills
     * (~10,000 entries) — a stop-the-world sweep causing 60-80ms pauses.
     *
     * With PHP GC disabled, circular reference cleanup depends entirely on
     * worker recycling via --max-requests (swoole.options.max_request).
     *
     * IMPORTANT: When using this listener, always set max_request to a
     * reasonable value (e.g., 1000-2000). Without both PHP GC and worker
     * recycling, memory will grow unbounded from accumulated circular
     * references (~0.52 MB/request for typical GraphQL mutations).
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        if ($event->app->make('config')->get('octane.disable_php_gc', false)) {
            gc_disable();
        }
    }
}
