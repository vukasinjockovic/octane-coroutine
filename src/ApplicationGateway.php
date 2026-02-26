<?php

namespace Laravel\Octane;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Facades\Octane;
use Symfony\Component\HttpFoundation\Response;
use Swoole\Coroutine;

class ApplicationGateway
{
    use DispatchesEvents;

    public function __construct(protected Application $app, protected Application $sandbox)
    {
    }

    private static function gState(string $state): void
    {
        $cid = Coroutine::getCid();
        if ($cid > 0) {
            $ctx = Coroutine::getContext($cid);
            if ($ctx) {
                $ctx['_debug_state'] = $state;
                $ctx['_debug_ts'] = microtime(true);
            }
        }
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request): Response
    {
        $request->enableHttpMethodParameterOverride();

        self::gState("gw_event_dispatch:{$request->getPathInfo()}");

        $this->dispatchEvent($this->sandbox, new RequestReceived($this->app, $this->sandbox, $request));

        if (Octane::hasRouteFor($request->getMethod(), '/'.$request->path())) {
            self::gState("gw_octane_route:{$request->getPathInfo()}");
            return Octane::invokeRoute($request, $request->getMethod(), '/'.$request->path());
        }

        self::gState("gw_resolve_kernel:{$request->getPathInfo()}");

        $kernel = $this->sandbox->make(Kernel::class);

        self::gState("gw_kernel_handle:{$request->getPathInfo()}");

        return tap($kernel->handle($request), function ($response) use ($request) {
            self::gState("gw_kernel_done:{$request->getPathInfo()}");
            $this->dispatchEvent($this->sandbox, new RequestHandled($this->sandbox, $request, $response));
        });
    }

    /**
     * "Shut down" the application after a request.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->sandbox->make(Kernel::class)->terminate($request, $response);

        $this->dispatchEvent($this->sandbox, new RequestTerminated($this->app, $this->sandbox, $request, $response));

        $route = $request->route();

        if ($route instanceof Route && method_exists($route, 'flushController')) {
            $route->flushController();
        }
    }
}
