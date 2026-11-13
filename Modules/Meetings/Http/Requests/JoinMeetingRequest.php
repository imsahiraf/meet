<?php

namespace Modules\Meetings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
