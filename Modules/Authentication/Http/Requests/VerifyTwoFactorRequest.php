<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string'],
            // Web-only in practice (only WebAuthController reads it) -
            // shared with the API request shape since it's harmless to
            // accept and ignore there, same as this class already is
            // shared between the two.
            'remember_device' => ['sometimes', 'boolean'],
        ];
    }
}
