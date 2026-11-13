<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class RetentionPolicy extends Model
{
    use HasFactory;

    public const MEDIA = 'media';
    public const ACTIVITY_LOGS = 'activity_logs';
    public const AUDIT_LOGS = 'audit_logs';
    public const ARCHIVES = 'archives';

    protected $fillable = ['category', 'retention_value', 'retention_unit'];

    /**
     * Null retention_value means "Never" (FR-065) - the category is
     * exempt from cleanup.
     */
    public function cutoff(): ?Carbon
    {
        if ($this->retention_value === null) {
            return null;
        }

        return $this->retention_unit === 'hours'
            ? now()->subHours($this->retention_value)
            : now()->subDays($this->retention_value);
    }
}
