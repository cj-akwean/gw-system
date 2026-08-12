<?php

namespace App\Http\Requests;

use App\Services\OtpService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', function (string $attribute, mixed $value, callable $fail): void {
                if (! Hash::check($value, (string) $this->user()->password)) {
                    $fail('The current password is incorrect.');
                }
            }],
            'otp' => ['required', 'string', 'size:6', function (string $attribute, mixed $value, callable $fail): void {
                if (! app(OtpService::class)->verify($this->user(), OtpService::PASSWORD_CHANGE, $value)) {
                    $fail('That verification code is invalid or has expired.');
                }
            }],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
