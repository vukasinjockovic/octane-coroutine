<?php

namespace Laravel\Octane\Listeners;

use Illuminate\Container\Container;
use Illuminate\Pagination\PaginationState;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;

class GiveNewRequestInstanceToPaginator
{
    /**
     * Handle the event.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        $container = Container::getInstance();

        if ($container instanceof CoroutineApplication) {
            PaginationState::resolveUsing($container);
            return;
        }

        PaginationState::resolveUsing($event->sandbox);
    }
}
