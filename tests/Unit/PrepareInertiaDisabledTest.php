<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laravel\Octane\Octane;
use Laravel\Octane\Listeners\PrepareInertiaForNextOperation;

/**
 * Test that PrepareInertiaForNextOperation is excluded from the default
 * operation listeners returned by Octane::prepareApplicationForNextOperation().
 *
 * The Inertia listener is unnecessary overhead for projects that don't use
 * Inertia, and since it's included via the spread operator in config/octane.php,
 * the cleanest way to disable it is to remove it from the source array.
 */
class PrepareInertiaDisabledTest extends TestCase
{
    public function test_inertia_listener_is_not_in_prepare_application_for_next_operation()
    {
        $listeners = Octane::prepareApplicationForNextOperation();

        $this->assertNotContains(
            PrepareInertiaForNextOperation::class,
            $listeners,
            'PrepareInertiaForNextOperation should be commented out from prepareApplicationForNextOperation()'
        );
    }

    public function test_other_first_party_listeners_still_present()
    {
        // Ensure we only removed Inertia, not the other first-party listeners
        $listeners = Octane::prepareApplicationForNextOperation();

        $this->assertContains(
            \Laravel\Octane\Listeners\PrepareLivewireForNextOperation::class,
            $listeners,
            'PrepareLivewireForNextOperation should still be present'
        );

        $this->assertContains(
            \Laravel\Octane\Listeners\PrepareScoutForNextOperation::class,
            $listeners,
            'PrepareScoutForNextOperation should still be present'
        );

        $this->assertContains(
            \Laravel\Octane\Listeners\PrepareSocialiteForNextOperation::class,
            $listeners,
            'PrepareSocialiteForNextOperation should still be present'
        );
    }
}
