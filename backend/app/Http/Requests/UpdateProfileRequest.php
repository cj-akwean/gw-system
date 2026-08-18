<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:20'],
            'avatar_id' => ['required', 'integer', 'between:1,4'],
            // PH mobile numbers, loosely: 09171234567, 639171234567 or +639171234567.
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^(09\d{9}|\+?639\d{9})$/'],
        ];
    }
}
