<?php

namespace App\Console\Commands;

use App\Services\Tasksbase\NotificationBroadcastService;
use Illuminate\Console\Command;

class BroadcastNotificationToUsers extends Command
{
    protected $signature = 'notifications:broadcast
        {message : Текст уведомления}
        {--title=Tasksbase : Заголовок push-уведомления}
        {--type=system : Тип уведомления (App\\Support\\Notifications\\NotificationType)}
        {--role= : Рассылать только пользователям с указанной ролью}
        {--include-inactive : Включить пользователей не в статусе active}
        {--chunk=200 : Размер порции получателей}
        {--dry-run : Только подсчитать получателей}';

    protected $description = 'Рассылает системное уведомление всем (или выбранной роли) пользователям';

    public function handle(NotificationBroadcastService $broadcasts): int
    {
        $message = trim((string) $this->argument('message'));

        if ($message === '') {
            $this->error('Текст уведомления не может быть пустым.');

            return self::FAILURE;
        }

        $type = (string) $this->option('type');
        $role = $this->option('role') ? (string) $this->option('role') : null;
        $includeInactive = (bool) $this->option('include-inactive');

        $total = $broadcasts->count($role, $includeInactive);

        if ($total === 0) {
            $this->warn('Получатели не найдены.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['Получателей', 'Тип'], [[$total, $type]]);
            $this->info('Dry run: ничего не отправлено.');

            return self::SUCCESS;
        }

        $sent = $broadcasts->send(
            $message,
            $type,
            (string) $this->option('title'),
            $role,
            $includeInactive,
            (int) $this->option('chunk'),
        );

        $this->table(['Отправлено', 'Тип'], [[$sent, $type]]);
        $this->info('Рассылка завершена.');

        return self::SUCCESS;
    }
}
