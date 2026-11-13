<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', 'in:👍,❤️,😂,😮,😢,🙏'],
        ];
    }
}
