<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends ForgotPasswordRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'regex:/\A[\s\S]{1,72}\z/', Password::defaults()],
        ];
    }
}
