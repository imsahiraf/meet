<?php

namespace Modules\Groups\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['required', 'string', 'max:150'],
            'allow_multiple_votes' => ['sometimes', 'boolean'],
            'closes_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
