<?php

namespace App\Providers;

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
use App\Services\Calendar\DnsHostResolver;
use App\Services\Calendar\HostResolver;
use App\Services\Permissions\PermissionGate;
use App\Services\Storage\StorageConfig;
use App\Services\Storage\StorageDriverFactory;
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
    }
}
