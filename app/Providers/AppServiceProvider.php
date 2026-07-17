<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $userKey = $request->user()?->getAuthIdentifier();
            $key     = is_scalar($userKey) === true ? (string) $userKey : (string) $request->ip();

            return Limit::perMinute(60)->by($key !== '' ? $key : 'anonymous');
        });

        if (config('starter.features.passport') === true) {
            Passport::tokensExpireIn(CarbonInterval::days(15));
            Passport::refreshTokensExpireIn(CarbonInterval::days(30));
            Passport::personalAccessTokensExpireIn(CarbonInterval::months(6));
        }
    }
}
