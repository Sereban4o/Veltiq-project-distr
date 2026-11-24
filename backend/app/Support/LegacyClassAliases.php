<?php

namespace App\Support;

/**
 * Backward-compatible aliases for classes that moved into modules.
 *
 * Queued jobs (Horizon), `failed_jobs` and third-party modules may still use the
 * old locations; aliases keep such payloads working for one release instead of
 * failing with `__PHP_Incomplete_Class` (docs/11-extensions.md).
 */
final class LegacyClassAliases
{
    /** @var array<string, string> old FQCN => new FQCN */
    private const ALIASES = [
        // CRM module
        'App\Models\Crm\Deal' => 'App\Extensions\Crm\Models\Deal',
        'App\Models\Crm\Company' => 'App\Extensions\Crm\Models\Company',
        'App\Models\Crm\Contact' => 'App\Extensions\Crm\Models\Contact',
        'App\Models\Crm\Activity' => 'App\Extensions\Crm\Models\Activity',
        'App\Models\Crm\PipelineStage' => 'App\Extensions\Crm\Models\PipelineStage',
        'App\Models\Crm\CrmAutomationRule' => 'App\Extensions\Crm\Models\CrmAutomationRule',
        'App\Models\Crm\CrmDealStageHistory' => 'App\Extensions\Crm\Models\CrmDealStageHistory',
        'App\Models\Crm\CrmFileLink' => 'App\Extensions\Crm\Models\CrmFileLink',
        'App\Observers\DealObserver' => 'App\Extensions\Crm\Observers\DealObserver',
        'App\Services\Crm\CrmAutomationService' => 'App\Extensions\Crm\Services\CrmAutomationService',
        'App\Services\Crm\CrmCsvService' => 'App\Extensions\Crm\Services\CrmCsvService',
        'App\Services\Crm\CrmReportsService' => 'App\Extensions\Crm\Services\CrmReportsService',
        'App\Services\Crm\MailCrmLinker' => 'App\Extensions\Crm\Services\MailCrmLinker',

        // Calendar module
        'App\Models\CalendarEvent' => 'App\Extensions\Calendar\Models\CalendarEvent',
        'App\Models\CalendarEventAttendee' => 'App\Extensions\Calendar\Models\CalendarEventAttendee',
        'App\Models\CalendarEventLink' => 'App\Extensions\Calendar\Models\CalendarEventLink',
        'App\Models\CalendarFeed' => 'App\Extensions\Calendar\Models\CalendarFeed',
        'App\Models\CalendarFeedEvent' => 'App\Extensions\Calendar\Models\CalendarFeedEvent',
        'App\Services\Calendar\CalendarAggregator' => 'App\Extensions\Calendar\Services\CalendarAggregator',
        'App\Services\Calendar\FeedSyncService' => 'App\Extensions\Calendar\Services\FeedSyncService',
        'App\Services\Calendar\FreeSlotFinder' => 'App\Extensions\Calendar\Services\FreeSlotFinder',
        'App\Services\Calendar\IcsService' => 'App\Extensions\Calendar\Services\IcsService',
        'App\Services\Calendar\RecurrenceExpander' => 'App\Extensions\Calendar\Services\RecurrenceExpander',
        'App\Services\Calendar\SafeUrlFetcher' => 'App\Extensions\Calendar\Services\SafeUrlFetcher',
        'App\Services\Calendar\DnsHostResolver' => 'App\Extensions\Calendar\Services\DnsHostResolver',
        'App\Services\Calendar\HostResolver' => 'App\Extensions\Calendar\Services\HostResolver',
        'App\Services\Calendar\UnsafeUrlException' => 'App\Extensions\Calendar\Services\UnsafeUrlException',
        'App\Jobs\SyncCalendarFeed' => 'App\Extensions\Calendar\Jobs\SyncCalendarFeed',
        'App\Console\Commands\SyncCalendarFeeds' => 'App\Extensions\Calendar\Console\SyncCalendarFeeds',

        // Storage module
        'App\Services\Storage\StorageConfig' => 'App\Extensions\Storage\Services\StorageConfig',
        'App\Services\Storage\StorageDriverFactory' => 'App\Extensions\Storage\Services\StorageDriverFactory',
        'App\Services\Storage\FileBrowserService' => 'App\Extensions\Storage\Services\FileBrowserService',
        'App\Services\Storage\FileUploadService' => 'App\Extensions\Storage\Services\FileUploadService',
        'App\Services\Storage\StorageAccess' => 'App\Extensions\Storage\Services\StorageAccess',
        'App\Services\Storage\StoragePath' => 'App\Extensions\Storage\Services\StoragePath',
        'App\Services\Storage\StorageAddress' => 'App\Extensions\Storage\Services\StorageAddress',
        'App\Services\Storage\StorageSettings' => 'App\Extensions\Storage\Services\StorageSettings',
        'App\Services\Storage\StorageQuotaService' => 'App\Extensions\Storage\Services\StorageQuotaService',
        'App\Services\Storage\StorageShareService' => 'App\Extensions\Storage\Services\StorageShareService',
        'App\Services\Storage\StorageTrashService' => 'App\Extensions\Storage\Services\StorageTrashService',
        'App\Services\Storage\StorageUploadService' => 'App\Extensions\Storage\Services\StorageUploadService',
        'App\Services\Storage\StorageVersionService' => 'App\Extensions\Storage\Services\StorageVersionService',
        'App\Services\Storage\StoragePreviewService' => 'App\Extensions\Storage\Services\StoragePreviewService',
        'App\Services\Storage\StorageMetadataService' => 'App\Extensions\Storage\Services\StorageMetadataService',
        'App\Services\Storage\StorageAuditService' => 'App\Extensions\Storage\Services\StorageAuditService',
        'App\Services\Storage\OfficeJwt' => 'App\Extensions\Storage\Services\OfficeJwt',
        'App\Services\Storage\OfficeSettings' => 'App\Extensions\Storage\Services\OfficeSettings',
        'App\Services\Storage\OfficeDocumentTypes' => 'App\Extensions\Storage\Services\OfficeDocumentTypes',
        'App\Services\Storage\Contracts\StorageDriver' => 'App\Extensions\Storage\Services\Contracts\StorageDriver',
        'App\Services\Storage\Drivers\LocalDriver' => 'App\Extensions\Storage\Services\Drivers\LocalDriver',
        'App\Services\Storage\Drivers\SmbDriver' => 'App\Extensions\Storage\Services\Drivers\SmbDriver',
        'App\Services\Storage\Drivers\SftpDriver' => 'App\Extensions\Storage\Services\Drivers\SftpDriver',
        'App\Services\Storage\Drivers\FtpDriver' => 'App\Extensions\Storage\Services\Drivers\FtpDriver',
        'App\Services\Storage\Drivers\WebdavDriver' => 'App\Extensions\Storage\Services\Drivers\WebdavDriver',
        'App\Services\Storage\Drivers\FlysystemDriver' => 'App\Extensions\Storage\Services\Drivers\FlysystemDriver',
        'App\Jobs\PruneStorageVersions' => 'App\Extensions\Storage\Jobs\PruneStorageVersions',
        'App\Jobs\PruneStorageTrash' => 'App\Extensions\Storage\Jobs\PruneStorageTrash',
        'App\Jobs\PruneStorageUploads' => 'App\Extensions\Storage\Jobs\PruneStorageUploads',
        'App\Jobs\PruneStorageAudit' => 'App\Extensions\Storage\Jobs\PruneStorageAudit',
        'App\Jobs\GenerateStoragePreview' => 'App\Extensions\Storage\Jobs\GenerateStoragePreview',
        'App\Console\Commands\StoragePreviews' => 'App\Extensions\Storage\Console\StoragePreviews',

        // AI module
        'App\Services\Ai\AiAssistant' => 'App\Extensions\Ai\Services\AiAssistant',
        // Mail module
        'App\Services\Mail\MailAccountService' => 'App\Extensions\Mail\Services\MailAccountService',
        'App\Services\Mail\MailAttachmentService' => 'App\Extensions\Mail\Services\MailAttachmentService',
        'App\Services\Mail\MailRuleApplier' => 'App\Extensions\Mail\Services\MailRuleApplier',
        'App\Services\Mail\MailRuleEngine' => 'App\Extensions\Mail\Services\MailRuleEngine',
        'App\Services\Mail\MailSyncService' => 'App\Extensions\Mail\Services\MailSyncService',
        'App\Services\Mail\MailThreadResolver' => 'App\Extensions\Mail\Services\MailThreadResolver',
        'App\Services\Mail\MailTrashService' => 'App\Extensions\Mail\Services\MailTrashService',
        'App\Services\Mail\ImapClient' => 'App\Extensions\Mail\Services\ImapClient',
        'App\Services\Mail\Pop3Client' => 'App\Extensions\Mail\Services\Pop3Client',
        'App\Services\Mail\SmtpSender' => 'App\Extensions\Mail\Services\SmtpSender',
        'App\Services\Mail\MimeParser' => 'App\Extensions\Mail\Services\MimeParser',
        'App\Services\Mail\MailRemoteDeleter' => 'App\Extensions\Mail\Services\MailRemoteDeleter',
        'App\Jobs\SendScheduledMail' => 'App\Extensions\Mail\Jobs\SendScheduledMail',

        // Reports and automation modules
        'App\Http\Controllers\Api\Tasksbase\ReportController' => 'App\Extensions\Reports\Http\Controllers\ReportController',
        'App\Http\Controllers\Api\Tasksbase\TaskAutomationRuleController' => 'App\Extensions\Automation\Http\Controllers\TaskAutomationRuleController',
        'App\Services\Tasksbase\TaskAutomationEngine' => 'App\Extensions\Automation\Services\TaskAutomationEngine',
        'App\Models\TaskAutomationRule' => 'App\Extensions\Automation\Models\TaskAutomationRule',
        'App\Http\Controllers\Api\Chat\ChatController' => 'App\Extensions\Chat\Http\Controllers\ChatController',
        'App\Http\Controllers\Api\Chat\MessageController' => 'App\Extensions\Chat\Http\Controllers\MessageController',
        'App\Http\Controllers\Api\Chat\MessageSearchController' => 'App\Extensions\Chat\Http\Controllers\MessageSearchController',
        'App\Http\Controllers\Api\Chat\PinController' => 'App\Extensions\Chat\Http\Controllers\PinController',
        'App\Http\Controllers\Api\ReleasesController' => 'App\Extensions\Chat\Http\Controllers\ReleasesController',
        'App\Jobs\PushChatMessageNotifications' => 'App\Extensions\Chat\Jobs\PushChatMessageNotifications',
        'App\Http\Controllers\Api\Tasksbase\TimesheetController' => 'App\Extensions\Timesheet\Http\Controllers\TimesheetController',
        'App\Http\Controllers\Api\Tasksbase\IssueWorklogController' => 'App\Extensions\Timesheet\Http\Controllers\IssueWorklogController',
        'App\Http\Controllers\Api\Knowledgebase\SpaceController' => 'App\Extensions\Docs\Http\Controllers\SpaceController',

        // Tasks module
        'App\Http\Controllers\Api\Tasksbase\BoardController' => 'App\Extensions\Tasksbase\Http\Controllers\BoardController',
        'App\Http\Controllers\Api\Tasksbase\DashboardController' => 'App\Extensions\Tasksbase\Http\Controllers\DashboardController',
        'App\Http\Controllers\Api\Tasksbase\EpicController' => 'App\Extensions\Tasksbase\Http\Controllers\EpicController',
        'App\Http\Controllers\Api\Tasksbase\FilterController' => 'App\Extensions\Tasksbase\Http\Controllers\FilterController',
        'App\Http\Controllers\Api\Tasksbase\IssueChecklistController' => 'App\Extensions\Tasksbase\Http\Controllers\IssueChecklistController',
        'App\Http\Controllers\Api\Tasksbase\IssueController' => 'App\Extensions\Tasksbase\Http\Controllers\IssueController',
        'App\Http\Controllers\Api\Tasksbase\ProjectController' => 'App\Extensions\Tasksbase\Http\Controllers\ProjectController',
        'App\Http\Controllers\Api\Tasksbase\SprintController' => 'App\Extensions\Tasksbase\Http\Controllers\SprintController',
    ];

    public static function register(): void
    {
        foreach (self::ALIASES as $old => $new) {
            // A self-alias would make `class_alias()` try to redeclare the class.
            if ($old === $new) {
                continue;
            }

            if (class_exists($old, false) || interface_exists($old, false)) {
                continue;
            }

            if (class_exists($new) || interface_exists($new)) {
                class_alias($new, $old);
            }
        }
    }
}
