<?php

namespace App\Filament\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\Contracts\HasBeforeChallengeHook;
use Filament\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Admin login with differentiated failure messages.
 *
 * NOTE: authenticate() is a copy of Filament\Auth\Pages\Login::authenticate()
 * from filament v5.7.3, with the two failure points split:
 *   - invalid credentials        -> "Incorrect email or password."
 *   - valid credentials, no panel access -> "This account does not have access to the admin panel."
 * Re-diff against the vendor class on Filament upgrades.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        /** @var SessionGuard $authGuard */
        $authGuard = Filament::auth();

        $authProvider = $authGuard->getProvider(); /** @phpstan-ignore-line */
        $credentials = $this->getCredentialsFromFormData($data);
        $remember = $data['remember'] ?? false;
        $timeboxDuration = (int) config('auth.timebox_duration', 200_000);

        $user = app(Timebox::class)->call(function (Timebox $timebox) use ($authProvider, $authGuard, $credentials, $remember): Authenticatable {
            $this->fireAttemptingEvent($authGuard, $credentials, $remember);

            $user = $authProvider->retrieveByCredentials($credentials);

            if ((! $user) || (! $authProvider->validateCredentials($user, $credentials))) {
                $this->userUndertakingMultiFactorAuthentication = null;

                $this->fireFailedEvent($authGuard, $user, $credentials);
                $this->throwFailureValidationException('Incorrect email or password.');
            }

            $timebox->returnEarly();

            return $user;
        }, $timeboxDuration);

        $needsMultiFactorChallenge = app(Timebox::class)->call(function (Timebox $timebox) use ($user): bool {
            if (
                filled($this->userUndertakingMultiFactorAuthentication) &&
                (decrypt($this->userUndertakingMultiFactorAuthentication) === $user->getAuthIdentifier())
            ) {
                if ($this->isMultiFactorChallengeRateLimited($user)) {
                    return true;
                }

                $this->multiFactorChallengeForm->validate();

                return false;
            }

            foreach (Filament::getMultiFactorAuthenticationProviders() as $multiFactorAuthenticationProvider) {
                if (! $multiFactorAuthenticationProvider->isEnabled($user)) {
                    continue;
                }

                $this->userUndertakingMultiFactorAuthentication = encrypt($user->getAuthIdentifier());

                if ($multiFactorAuthenticationProvider instanceof HasBeforeChallengeHook) {
                    $multiFactorAuthenticationProvider->beforeChallenge($user);
                }

                break;
            }

            if (filled($this->userUndertakingMultiFactorAuthentication)) {
                $this->multiFactorChallengeForm->fill();

                return true;
            }

            return false;
        }, $timeboxDuration);

        if ($needsMultiFactorChallenge) {
            return null;
        }

        if (! $authGuard->attemptWhen($credentials, function (Authenticatable $user): bool {
            if (! ($user instanceof FilamentUser)) {
                return true;
            }

            return $user->canAccessPanel(Filament::getCurrentOrDefaultPanel());
        }, $remember)) {
            $this->fireFailedEvent($authGuard, $user, $credentials);
            $this->throwFailureValidationException('This account does not have access to the admin panel.');
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function throwFailureValidationException(string $message = 'Incorrect email or password.'): never
    {
        throw ValidationException::withMessages([
            'data.email' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function fireAttemptingEvent(Guard $guard, #[SensitiveParameter] array $credentials, bool $remember): void
    {
        event(app(Attempting::class, ['guard' => property_exists($guard, 'name') ? $guard->name : '', 'credentials' => $credentials, 'remember' => $remember]));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function fireFailedEvent(Guard $guard, ?Authenticatable $user, #[SensitiveParameter] array $credentials): void
    {
        event(app(Failed::class, ['guard' => property_exists($guard, 'name') ? $guard->name : '', 'user' => $user, 'credentials' => $credentials]));
    }
}
