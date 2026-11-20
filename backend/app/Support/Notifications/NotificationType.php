<?php

namespace App\Support\Notifications;

/**
 * Canonical registry of notification types, their UI grouping and per-channel
 * defaults. Used by the notification service, the preferences API and the UI.
 */
enum NotificationType: string
{
    case IssueMention = 'issue_mention';
    case IssueAssigned = 'issue_assigned';
    case IssueCommented = 'issue_commented';
    case IssueStatusChanged = 'issue_status_changed';
    case PageCommented = 'page_comment';
    case ChatMessage = 'chat_message';
    case CrmDealAssigned = 'crm_deal_assigned';
    case OneC = 'one_c';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::IssueMention => 'Упоминание в задаче',
            self::IssueAssigned => 'Назначение задачи',
            self::IssueCommented => 'Комментарий к задаче',
            self::IssueStatusChanged => 'Смена статуса задачи',
            self::PageCommented => 'Комментарий к странице',
            self::ChatMessage => 'Сообщение в чате',
            self::CrmDealAssigned => 'Сделка CRM',
            self::OneC => 'Событие 1С',
            self::System => 'Системное уведомление',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::IssueMention, self::IssueAssigned, self::IssueCommented, self::IssueStatusChanged => 'issues',
            self::PageCommented => 'pages',
            self::ChatMessage => 'chat',
            self::CrmDealAssigned => 'crm',
            self::OneC, self::System => 'system',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::IssueMention => 'Кто-то упомянул вас в задаче',
            self::IssueAssigned => 'Вам назначили задачу',
            self::IssueCommented => 'Новый комментарий к вашей задаче',
            self::IssueStatusChanged => 'Статус задачи, за которой вы следите, изменён',
            self::PageCommented => 'Новый комментарий на странице базы знаний',
            self::ChatMessage => 'Новое сообщение в чате',
            self::CrmDealAssigned => 'Вам назначена сделка в CRM',
            self::OneC => 'Событие из интеграции с 1С',
            self::System => 'Системные уведомления доставляются всегда',
        };
    }

    public function defaultsFor(NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::InApp => true,
            NotificationChannel::Email => true,
            NotificationChannel::Push => true,
        };
    }

    public function isUserConfigurable(): bool
    {
        return ! in_array($this, [self::System, self::OneC], true);
    }

    public static function tryFromString(string $type): self
    {
        return self::tryFrom($type) ?? self::System;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * @return array<string, array<string, string>> group => [value => label]
     */
    public static function grouped(): array
    {
        $groups = [
            'issues' => 'Задачи',
            'pages' => 'База знаний',
            'chat' => 'Чат',
            'crm' => 'CRM',
            'system' => 'Система',
        ];

        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->group()]['label'] = $groups[$case->group()] ?? $case->group();
            $result[$case->group()]['types'][$case->value] = $case->label();
        }

        return $result;
    }
}
