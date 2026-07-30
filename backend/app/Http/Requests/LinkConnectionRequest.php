<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_number' => ['required', 'string', 'max:20'],
            'meter_number' => ['required', 'string', 'max:20'],
        ];
    }
}
