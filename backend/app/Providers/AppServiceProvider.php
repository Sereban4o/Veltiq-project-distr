<?php

namespace App\Providers;

use App\Extensions\Calendar\Services\DnsHostResolver;
use App\Extensions\Calendar\Services\HostResolver;
use App\Extensions\Storage\Services\StorageConfig;
use App\Extensions\Storage\Services\StorageDriverFactory;
use App\Models\Issue;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Space;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Observers\IssueWebhookObserver;
use App\Observers\MessageObserver;
use App\Observers\NotificationObserver;
use App\Services\Extensions\ExtensionRegistry;
use App\Services\Permissions\PermissionGate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Used by the calendar feed fetcher (SSRF guard) — must be resolvable
        // from the container, not only via a test fake.
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        $this->app->scoped(StorageConfig::class, fn () => StorageConfig::fromSettings());
        $this->app->bind(StorageDriverFactory::class, fn ($app, array $params = []) => new StorageDriverFactory($params['config'] ?? $app->make(StorageConfig::class)));

        // Mount extension providers (docs/11-extensions.md). All discovered extensions are
        // mounted so `route:cache` and `config:cache` stay deterministic and the
        // route table does not depend on a warm runtime cache; whether a module
        // actually answers is decided by the `extension.enabled` middleware.
        $registry = $this->app->make(ExtensionRegistry::class);
        $registry->mount($this->app);

        // Keep old class names working for one release: queued job payloads and
        // third-party extensions may still reference pre-extension locations.
        \App\Support\LegacyClassAliases::register();
    }

    public function boot(): void
    {
        Notification::observe(NotificationObserver::class);

        // Keeps the chat blind index (message_search_terms) in sync on create/
        // edit/delete; message bodies are encrypted at rest and cannot be
        // searched with SQL, and are never sent to Meilisearch.
        Message::observe(MessageObserver::class);

        // Outbound webhooks for external integrations (§12 extension point).
        Issue::observe(IssueWebhookObserver::class);

        // Generic audit trail for models that carry no bespoke activity logging
        // (issues are recorded explicitly by IssueController with richer actions).
        Project::observe(AuditObserver::class);
        Space::observe(AuditObserver::class);
        User::observe(AuditObserver::class);
        Role::observe(AuditObserver::class);
        Permission::observe(AuditObserver::class);

        // Permission checks through Gate/@can, scoped via the model argument.
        PermissionGate::register();

        // One styled paginator for every admin listing (the framework default
        // ships a Tailwind markup that is unstyled outside the SPA).
        \Illuminate\Pagination\Paginator::defaultView('admin.partials.pagination');
        \Illuminate\Pagination\Paginator::defaultSimpleView('admin.partials.pagination');
    }
}
