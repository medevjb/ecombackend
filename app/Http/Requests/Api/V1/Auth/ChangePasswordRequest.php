<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'regex:/\A[\s\S]{1,72}\z/'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', 'regex:/\A[\s\S]{1,72}\z/', Password::defaults()],
        ];
    }
}
