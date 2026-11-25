<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.api_per_minute', 600))
                ->by($request->user()?->id ?: $request->ip());
        });

        // Blade admin login. Keyed by e-mail + IP so one shared public IP (office
        // NAT) cannot lock every operator out after a handful of attempts, and
        // answered with a readable message instead of the bare 429 error page.
        RateLimiter::for('admin-login', function (Request $request) {
            return Limit::perMinute(10)
                ->by(mb_strtolower((string) $request->input('email')) . '|' . $request->ip())
                ->response(fn () => redirect()
                    ->route('admin.login.form')
                    ->withErrors(['email' => 'Слишком много попыток входа. Подождите минуту и попробуйте снова.']));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
