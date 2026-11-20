<?php

namespace App\Providers;

use App\Models\StaffUser;
use App\Models\User;
use App\Services\Auth\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

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
        $this->configureDefaults();

        RateLimiter::for('api-login', function (Request $request): array {
            $email = $request->input('email');
            $identity = is_string($email) ? mb_strtolower(substr($email, 0, 255)) : '';

            return [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('account:'.$identity.'|'.$request->ip())
                    ->after(fn (Response $response): bool => $response->getStatusCode() === 401),
            ];
        });

        RateLimiter::for('stripe-webhooks', fn (Request $request): Limit => Limit::perMinute(300)->by($request->ip()));
        RateLimiter::for('checkout', fn (Request $request): array => [
            Limit::perMinute(30)->by('ip:'.$request->ip()),
            Limit::perMinute(12)->by('customer:'.$request->user()?->getAuthIdentifier()),
        ]);
        RateLimiter::for('coupon-apply', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('customer-recovery', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('staff-two-factor-manage', fn (Request $request): Limit => Limit::perMinute(15)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('staff-two-factor-challenge', fn (Request $request): Limit => Limit::perMinute(15)->by($request->ip()));

        Gate::define('customer-account', fn (User $user): bool => User::query()->customers()->whereKey($user->getKey())->exists());

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $origin = config($user->staffIdentity()->exists() ? 'auth.admin_url' : 'auth.storefront_url');

            return rtrim((string) $origin, '/').'/reset-password?'.http_build_query(
                ['token' => $token, 'email' => $user->email],
                encoding_type: PHP_QUERY_RFC3986,
            );
        });

        VerifyEmail::createUrlUsing(function (User $user): string {
            $signedUrl = URL::temporarySignedRoute(
                'api.v1.auth.verification.verify',
                now()->addMinutes(60),
                ['id' => $user->public_id, 'hash' => sha1($user->getEmailForVerification())],
                absolute: false,
            );
            parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $signature);

            return rtrim((string) config('auth.storefront_url'), '/').'/verify-email?'.http_build_query([
                'id' => $user->public_id,
                'hash' => sha1($user->getEmailForVerification()),
                'expires' => $signature['expires'] ?? '',
                'signature' => $signature['signature'] ?? '',
            ], encoding_type: PHP_QUERY_RFC3986);
        });

        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }

            $identity = $token->tokenable;

            return match (true) {
                $identity instanceof StaffUser => ! $identity->account->isSuspended(),
                $identity instanceof User => ! $identity->isSuspended() && ! $identity->staffIdentity()->exists(),
                default => false,
            };
        });

        foreach (config('staff-access.permissions') as $permission) {
            Gate::define($permission, fn (StaffUser $staff): bool => app(StaffAccess::class)->allows($staff, $permission));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(function (): Password {
            $rule = Password::min(12)->mixedCase()->numbers()->symbols();

            return app()->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}
