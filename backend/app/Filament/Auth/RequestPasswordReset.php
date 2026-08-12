<?php

namespace App\Filament\Auth;

use App\Mail\PasswordResetOtp;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Forgot-password request page that emails a 6-digit OTP instead of a reset
 * link. The code IS the password broker's token (OtpTokenRepository): hashed,
 * single-use, 15-minute expiry, 60s resend throttle. Re-diff request() against
 * the vendor class on Filament upgrades (same pattern as the admin Login).
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                if (
                    ($user instanceof FilamentUser) &&
                    (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
                ) {
                    return;
                }

                Mail::to($user)->queue(new PasswordResetOtp($user, $token));

                if (class_exists(PasswordResetLinkSent::class)) {
                    event(new PasswordResetLinkSent($user));
                }
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->form->fill();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return Notification::make()
            ->title(__($status))
            ->body(__('filament-panels::auth/pages/password-reset/request-password-reset.notifications.sent.body'))
            ->success()
            ->actions([
                Action::make('enterCode')
                    ->label('Enter the code')
                    ->link()
                    ->url(Filament::getUrl().'/password-reset/reset-code'),
            ]);
    }
}
