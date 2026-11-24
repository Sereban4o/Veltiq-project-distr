<?php

namespace App\Extensions\Crm;

use App\Extensions\ExtensionServiceProvider;

/**
 * CRM module provider — a licensed module (entitlement `crm`).
 *
 * Domain code lives in this module (Models/Http/Services);
 * this provider only declares the boundary so the module can be gated.
 */
class CrmModuleProvider extends ExtensionServiceProvider
{
}
