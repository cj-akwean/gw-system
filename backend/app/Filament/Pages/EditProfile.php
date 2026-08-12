<?php

namespace App\Filament\Pages;

use App\Mail\PasswordChanged;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Mail;

/**
 * Admin profile settings: avatar (the same 1-4 set the customer portal uses),
 * display name, email and password. Name/email/password handling, rate
 * limiting and hashing come from Filament's EditProfile; the avatar field is
 * prepended and persisted through the same `$record->update($data)` path.
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
            ]);
    }

    protected function afterSave(): void
    {
        if (! empty($this->data['password'])) {
            Mail::to($this->getUser())->queue(new PasswordChanged($this->getUser()));
        }
    }
}
