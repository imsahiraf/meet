<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['setting_key', 'setting_value', 'category', 'data_type', 'is_editable'];

    protected function casts(): array
    {
        return ['is_editable' => 'boolean'];
    }

    public function castValue(): mixed
    {
        return match ($this->data_type) {
            'integer' => (int) $this->setting_value,
            'boolean' => filter_var($this->setting_value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->setting_value, true),
            default => $this->setting_value,
        };
    }
}
