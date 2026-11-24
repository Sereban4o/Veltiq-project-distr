<?php

namespace App\Extensions\Calendar\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Calendar endpoints are ownership/participant sensitive, so the acting user
 * must reflect the token on the *current* request. The auth guard memoises the
 * user it resolved, which leaks the previous request's identity in long-lived
 * workers (and in the test harness); re-reading the bearer token keeps every
 * ownership check anchored to the request being handled.
 */
trait ResolvesCalendarActor
{
    protected function actor(Request $request): ?User
    {
        $token = $request->bearerToken();

        if ($token !== null && $token !== '') {
            $user = PersonalAccessToken::findToken($token)?->tokenable;

            if ($user instanceof User) {
                $request->setUserResolver(fn () => $user);

                return $user;
            }
        }

        return $request->user();
    }
}
