<?php

namespace App\Extensions\Tasksbase;

use App\Extensions\ExtensionServiceProvider;

/**
 * Tasks module: projects, issues, kanban/scrum boards, sprints, epics,
 * dashboards and saved filters. Models, services and the core table
 * migrations stay in app/ because other modules (calendar, crm, mail, reports,
 * roadmap, timesheet, automation) and core jobs reference them.
 */
class TasksbaseModuleProvider extends ExtensionServiceProvider
{
}
