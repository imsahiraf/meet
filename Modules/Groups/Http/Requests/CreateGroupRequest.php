<?php

namespace Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group_name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'exists:users,id'],
        ];
    }
}
