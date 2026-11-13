<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = ['feature_key', 'label', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public static function enabled(string $key, bool $default = true): bool
    {
        $flag = static::where('feature_key', $key)->first();

        return $flag ? $flag->is_enabled : $default;
    }
}
