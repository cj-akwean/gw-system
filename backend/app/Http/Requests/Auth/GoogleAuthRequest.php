<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GoogleAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'credential.required' => 'A Google credential is required.',
        ];
    }
}