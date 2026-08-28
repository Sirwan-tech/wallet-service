<?php

namespace App\Providers;

use App\Http\Middleware\ApiSecurityHeaders;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Login failures get a small credential-and-IP bucket plus a wider
        // IP-only bucket. The keys are HMACs so emails, phones and IPs are not
        // stored in plain text by the cache backend.
        RateLimiter::for('login', function (Request $request): array {
            $ipAddress = (string) $request->ip();
            $login = mb_strtolower(trim((string) $request->input('login', '')));

            return [
                Limit::perMinute((int) config('wallet.rate_limits.login_per_minute'))
                    ->by('login:credential-ip:' . $this->rateLimitKey($login . '|' . $ipAddress))
                    ->after(fn ($response): bool => $response->getStatusCode() === 401)
                    ->response($this->rateLimitResponse()),
                Limit::perMinute((int) config('wallet.rate_limits.login_ip_per_minute'))
                    ->by('login:ip:' . $this->rateLimitKey($ipAddress))
                    ->after(fn ($response): bool => $response->getStatusCode() === 401)
                    ->response($this->rateLimitResponse()),
            ];
        });

        RateLimiter::for('registration', function (Request $request): array {
            $ipAddress = (string) $request->ip();

            return [
                Limit::perMinute((int) config('wallet.rate_limits.registration_per_minute'))
                    ->by('registration:ip:' . $this->rateLimitKey($ipAddress))
                    ->response($this->rateLimitResponse()),
                Limit::perHour((int) config('wallet.rate_limits.registration_per_hour'))
                    ->by('registration:ip:' . $this->rateLimitKey($ipAddress))
                    ->response($this->rateLimitResponse()),
            ];
        });

        RateLimiter::for('money-operation', function (Request $request): array {
            $ipAddress = (string) $request->ip();
            $principal = (string) ($request->user()?->getAuthIdentifier() ?? $ipAddress);

            return [
                Limit::perMinute((int) config('wallet.rate_limits.money_per_minute'))
                    ->by('money:account:' . $this->rateLimitKey($principal))
                    ->response($this->rateLimitResponse()),
                Limit::perMinute((int) config('wallet.rate_limits.money_ip_per_minute'))
                    ->by('money:ip:' . $this->rateLimitKey($ipAddress))
                    ->response($this->rateLimitResponse()),
            ];
        });

        RateLimiter::for('authenticated-post', function (Request $request): array {
            $ipAddress = (string) $request->ip();
            $principal = (string) ($request->user()?->getAuthIdentifier() ?? $ipAddress);

            return [
                Limit::perMinute((int) config('wallet.rate_limits.authenticated_post_per_minute'))
                    ->by('authenticated-post:account:' . $this->rateLimitKey($principal))
                    ->response($this->rateLimitResponse()),
            ];
        });
    }

    private function rateLimitKey(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function rateLimitResponse(): Closure
    {
        return static fn (Request $request, array $headers): JsonResponse => ApiSecurityHeaders::apply(
            response()->json([
                'error' => [
                    'code' => 'rate_limited',
                    'message' => 'Too many requests. Please try again later.',
                ],
            ], 429, $headers)
        );
    }
}
