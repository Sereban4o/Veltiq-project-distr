<?php

namespace App\Extensions\Ai;

use App\Extensions\Ai\Console\PruneAiConversations;
use App\Extensions\Ai\Retrieval\AiRetriever;
use App\Extensions\Ai\Retrieval\Providers\CalendarProvider;
use App\Extensions\Ai\Retrieval\Providers\CompanyProvider;
use App\Extensions\Ai\Retrieval\Providers\ContactProvider;
use App\Extensions\Ai\Retrieval\Providers\CoreSearchProvider;
use App\Extensions\Ai\Retrieval\Providers\DealProvider;
use App\Extensions\Ai\Retrieval\Providers\EmployeeProvider;
use App\Extensions\Ai\Retrieval\Providers\WorklogProvider;
use App\Extensions\Ai\Services\EmployeeDirectory;
use App\Extensions\ExtensionServiceProvider;
use App\Services\Extensions\ExtensionRegistry;
use App\Services\Search\GlobalSearchService;
use Illuminate\Support\Facades\Schedule;

/**
 * AI assistant module: assistant endpoints, stored conversations, the
 * multi-section retriever and the Ollama-backed service.
 */
class AiModuleProvider extends ExtensionServiceProvider
{
    public function register(): void
    {
        $this->commands([PruneAiConversations::class]);

        // Providers are plain objects, so the whole source list is easy to
        // replace in tests and never hides a dependency in the container.
        $this->app->singleton(AiRetriever::class, function ($app): AiRetriever {
            $directory = $app->make(EmployeeDirectory::class);

            return new AiRetriever([
                new CoreSearchProvider($app->make(GlobalSearchService::class)),
                new CalendarProvider(),
                new DealProvider(),
                new CompanyProvider(),
                new ContactProvider(),
                new WorklogProvider(),
                new EmployeeProvider($directory),
            ], $app->make(ExtensionRegistry::class), $directory);
        });
    }

    public function boot(): void
    {
        parent::boot();

        if (! $this->app->runningInConsole()) {
            return;
        }

        // The module owns its schedule (docs/11-extensions.md) and stays quiet
        // while it is switched off.
        Schedule::command('ai:prune-conversations')
            ->dailyAt('03:55')
            ->withoutOverlapping(60)
            ->when(fn () => $this->app->make(ExtensionRegistry::class)->isEnabled('ai'));
    }
}
