<?php

namespace Modules\Media\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(config('media.max_upload_mb') * 1024)],
            'is_voice_note' => ['sometimes', 'boolean'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
