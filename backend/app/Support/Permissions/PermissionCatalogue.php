<?php

namespace App\Support\Permissions;

/**
 * Single source of truth for permission names, their UI grouping and the
 * scopes (global / project / space) where each permission can be granted.
 */
final class PermissionCatalogue
{
    public const SCOPES = ['global', 'project', 'space'];

    /**
     * group => [name => [label, scopes]]
     *
     * @return array<string, array<string, array{0: string, 1: array<int, string>}>>
     */
    public static function all(): array
    {
        return [
            'project' => [
                'view_projects' => ['Просмотр проектов', ['global', 'project']],
                'create_projects' => ['Создание проектов', ['global']],
                'edit_projects' => ['Редактирование проектов', ['global', 'project']],
                'delete_projects' => ['Удаление проектов', ['global', 'project']],
                'manage_members' => ['Управление участниками', ['global', 'project']],
            ],
            'issue' => [
                'view_issues' => ['Просмотр задач', ['global', 'project']],
                'create_issues' => ['Создание задач', ['global', 'project']],
                'edit_issues' => ['Редактирование задач', ['global', 'project']],
                'delete_issues' => ['Удаление задач', ['global', 'project']],
                'transition_issues' => ['Смена статуса задач', ['global', 'project']],
                'manage_issue_types' => ['Управление типами задач', ['global']],
            ],
            'board' => [
                'view_boards' => ['Просмотр досок', ['global', 'project']],
                'edit_boards' => ['Редактирование досок', ['global', 'project']],
                'delete_boards' => ['Удаление досок', ['global', 'project']],
            ],
            'space' => [
                'view_spaces' => ['Просмотр пространств', ['global', 'space']],
                'create_spaces' => ['Создание пространств', ['global']],
                'edit_spaces' => ['Редактирование пространств', ['global', 'space']],
                'delete_spaces' => ['Удаление пространств', ['global', 'space']],
            ],
            'page' => [
                'view_pages' => ['Просмотр страниц', ['global', 'space']],
                'create_pages' => ['Создание страниц', ['global', 'space']],
                'edit_pages' => ['Редактирование страниц', ['global', 'space']],
                'delete_pages' => ['Удаление страниц', ['global', 'space']],
                'publish_pages' => ['Публикация страниц', ['global', 'space']],
            ],
            'admin' => [
                'manage_users' => ['Управление пользователями', ['global']],
                'manage_roles' => ['Управление ролями и правами', ['global']],
                'manage_settings' => ['Управление настройками', ['global']],
                'view_audit_log' => ['Просмотр журнала действий', ['global']],
                'view_login_logs' => ['Просмотр журнала входов', ['global']],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $permissions) {
            $names = array_merge($names, array_keys($permissions));
        }

        return $names;
    }

    /**
     * @return array<string, string> group => label
     */
    public static function groups(): array
    {
        return [
            'project' => 'Проекты',
            'issue' => 'Задачи',
            'board' => 'Доски',
            'space' => 'Пространства',
            'page' => 'Страницы',
            'admin' => 'Администрирование',
        ];
    }

    /**
     * Grouped permissions for the admin UI.
     *
     * @return array<int, array{key:string,label:string,permissions:array<string,string>}>
     */
    public static function grouped(): array
    {
        $labels = self::groups();
        $result = [];

        foreach (self::all() as $group => $permissions) {
            $items = [];
            foreach ($permissions as $name => [$label]) {
                $items[$name] = $label;
            }

            $result[] = [
                'key' => $group,
                'label' => $labels[$group] ?? $group,
                'permissions' => $items,
            ];
        }

        return $result;
    }

    public static function label(string $name): string
    {
        foreach (self::all() as $permissions) {
            if (isset($permissions[$name])) {
                return $permissions[$name][0];
            }
        }

        return $name;
    }

    /**
     * @return array<int, string>
     */
    public static function scopesOf(string $name): array
    {
        foreach (self::all() as $permissions) {
            if (isset($permissions[$name])) {
                return $permissions[$name][1];
            }
        }

        return [];
    }

    public static function allowsScope(string $name, string $scope): bool
    {
        return in_array($scope, self::scopesOf($name), true);
    }

    /**
     * Baseline permissions for a membership role inside a scope.
     *
     * Extracted from the previously hard-coded checks in Project::hasPermission()
     * and Space::isAdmin()/canEdit()/canView().
     *
     * @return array<int, string>
     */
    public static function roleBaseline(string $scopeType, string $role): array
    {
        if ($role === 'admin') {
            return self::names();
        }

        return match ($scopeType . ':' . $role) {
            'project:member' => [
                'view_issues', 'create_issues', 'edit_issues', 'transition_issues',
                'view_boards', 'edit_boards',
            ],
            'project:viewer' => ['view_issues'],
            'space:editor' => [
                'view_spaces', 'view_pages', 'create_pages', 'edit_pages', 'publish_pages',
            ],
            'space:viewer' => ['view_spaces', 'view_pages'],
            default => [],
        };
    }
}
