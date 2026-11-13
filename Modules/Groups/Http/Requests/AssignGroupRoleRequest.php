<?php

namespace Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignGroupRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer'],
        ];
    }
}
