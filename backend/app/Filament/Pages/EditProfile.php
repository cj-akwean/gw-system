<?php

namespace App\Filament\Pages;

use App\Mail\PasswordChanged;
use App\Services\OtpService;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;

/**
 * Admin profile settings: avatar (the same 1-4 set the customer portal uses),
 * display name, email and password. Name/email/password handling, rate
 * limiting and hashing come from Filament's EditProfile; the avatar field is
 * prepended and persisted through the same `$record->update($data)` path.
 *
 * Changing the password now requires an email OTP: a "Send code" button mails
 * a 6-digit code (OtpService, 5-minute expiry) and `save()` refuses to run
 * until the code is verified — the OTP field appears alongside the
 * confirm/current-password fields once a new password is typed.
 */
class EditProfile extends BaseEditProfile
{
    protected function getAvatarFormComponent(): Component
    {
        $options = [];

        foreach (range(1, 4) as $id) {
            $options[$id] = '<span style="display:inline-flex;align-items:center;gap:8px;min-height:28px;">'
                .'<img src="'.asset('avatars/avatar-'.$id.'.svg').'" alt="Avatar '.$id.'" width="28" height="28" '
                .'style="border-radius:50%;display:block;">Avatar '.$id.'</span>';
        }

        return Select::make('avatar_id')
            ->label('Avatar')
            ->placeholder('Default (initials)')
            ->options($options)
            ->allowHtml()
            ->rules(['nullable', 'integer', 'in:1,2,3,4']);
    }

    protected function getOtpFormComponent(): Component
    {
        return TextInput::make('otp')
            ->label('Verification code')
            ->numeric()
            ->minLength(6)
            ->maxLength(6)
            ->helperText('We emailed a 6-digit code to '.$this->getUser()->email.'.')
            ->dehydrated(false)
            ->visible(fn (Get $get): bool => filled($get('password')));
    }

    protected function getSendCodeFormComponent(): Component
    {
        return Actions::make([
            \Filament\Actions\Action::make('sendPasswordChangeCode')
                ->label('Send verification code')
                ->icon('heroicon-o-paper-airplane')
                ->action('sendPasswordChangeCode')
                ->visible(fn (Get $get): bool => filled($get('password'))),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getAvatarFormComponent(),
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                $this->getSendCodeFormComponent(),
                $this->getOtpFormComponent(),
            ]);
    }

    public function sendPasswordChangeCode(): void
    {
        if (blank($this->data['password'] ?? null)) {
            Notification::make()->title('Enter a new password first.')->warning()->send();

            return;
        }

        app(OtpService::class)->send($this->getUser(), OtpService::PASSWORD_CHANGE);

        Notification::make()
            ->title('Code sent')
            ->body('Check '.$this->getUser()->email.' and enter the code below.')
            ->success()
            ->send();
    }

    public function save(): void
    {
        $passwordWasChanged = filled($this->data['password'] ?? null);

        if ($passwordWasChanged) {
            $valid = app(OtpService::class)->verify(
                $this->getUser(),
                OtpService::PASSWORD_CHANGE,
                (string) ($this->data['otp'] ?? ''),
            );

            if (! $valid) {
                Notification::make()
                    ->title('Invalid or expired code')
                    ->body('Send a new code and try again.')
                    ->danger()
                    ->send();

                return;
            }
        }

        parent::save();

        // The base `save()` clears `$this->data['password']` only after a
        // committed save — its rate-limit early-returns leave it filled, so this
        // gate prevents logging the admin out when nothing was persisted.
        if ($passwordWasChanged && blank($this->data['password'])) {
            Filament::auth()->logout();

            if (request()->hasSession()) {
                request()->session()->invalidate();
                request()->session()->regenerateToken();
            }

            $this->redirect(filament()->getLoginUrl(), navigate: false);
        }
    }

    protected function afterSave(): void
    {
        if (! empty($this->data['password'])) {
            Mail::to($this->getUser())->queue(new PasswordChanged($this->getUser()));
        }
    }
}
