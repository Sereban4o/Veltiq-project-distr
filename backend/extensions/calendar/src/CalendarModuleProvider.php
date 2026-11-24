<?php

namespace App\Extensions\Calendar;

use App\Extensions\ExtensionServiceProvider;

/**
 * Calendar module provider.
 *
 * The domain code still lives in the application (`App\Services\Calendar`,
 * `App\Http\Controllers\Api\Calendar*`); this provider only declares the module
 * boundary, so enabling/disabling and licensing can gate it. Moving the code
 * itself is a later, separate step.
 */
class CalendarModuleProvider extends ExtensionServiceProvider
{
}
