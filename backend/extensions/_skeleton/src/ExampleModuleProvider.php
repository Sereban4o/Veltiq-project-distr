<?php

namespace Acme\Example;

use Acme\Example\Console\ExampleCommand;
use App\Extensions\ExtensionServiceProvider;

/**
 * Skeleton module provider.
 *
 * Migrations and routes are loaded by the base provider from `module.json`; only
 * things that are NOT auto-discovered (console commands outside app/Console,
 * bindings, observers) need to be registered here.
 */
class ExampleModuleProvider extends ExtensionServiceProvider
{
    public function register(): void
    {
        // $this->commands([ExampleCommand::class]);
    }

    public function boot(): void
    {
        parent::boot();

        // Bindings, observers, policies of this module.
    }
}
