<?php

namespace App\Filament\Auth;

use App\Mail\PasswordResetOtp;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Forgot-password reset page for the OTP flow: email + 6-digit code + new
 * password (no reset link in the URL). The code is passed to the password
 * broker as the reset token, which hashes and validates it (single-use,
 * 15-minute expiry). Re-diff resetPassword() against the vendor class on
 * Filament upgrades.
 */
class ResetPassword extends BaseResetPassword
{
    /**
     * The parent declares `#[Locked] public ?string $email` because its flow
     * prefills the email from the signed URL. The OTP flow has the user type
     * the email, so the property is redeclared here without Locked (PHP allows
     * redeclaring properties in a subclass; client updates now reach the page).
     */
    public ?string $email = '';

    /** Backing property for the OTP field (form state maps to public props). */
    public ?string $otp = '';

    protected function getOtpFormComponent(): Component
    {
        return TextInput::make('otp')
            ->label('Verification code')
            ->numeric()
            ->minLength(6)
            ->maxLength(6)
            ->required()
            ->autocomplete('one-time-code')
            ->autofocus();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getOtpFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    /**
     * The email field is normally disabled (filled from the reset URL); in the
     * OTP flow the user navigates here directly, so it must be editable.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/password-reset/reset-password.form.email.label'))
            ->email()
            ->required()
            ->autocomplete('email');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        // 'otp' is our form field, not a broker credential — the broker's user
        // lookup would otherwise query a users.otp column.
        unset($data['otp']);

        return $data;
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $data['email'] = (string) $data['email'];
        $data['token'] = (string) $data['otp'];

        if ($this->isResetPasswordRateLimited($data['email'])) {
            return null;
        }

        $hasPanelAccess = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword | Model | Authenticatable $user) use ($data, &$hasPanelAccess): void {
                if (
                    ($user instanceof FilamentUser) &&
                    (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
                ) {
                    $hasPanelAccess = false;

                    return;
                }

                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($hasPanelAccess === false) {
            $status = Password::INVALID_USER;
        }

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()
                ->title(__($status))
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()
            ->title(__($status))
            ->danger()
            ->send();

        return null;
    }
}
