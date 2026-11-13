<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePresenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * FR-032 - presence heartbeat, updated by the polling engine.
     */
    public function rules(): array
    {
        return [
            'presence_status' => ['required', 'in:online,offline,away,busy,invisible'],
        ];
    }
}
