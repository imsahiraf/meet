<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Admin\Models\BrandingSetting;
use Modules\Admin\Models\FeatureFlag;
use Modules\Admin\Models\Permission;
use Modules\Admin\Models\RetentionPolicy;
use Modules\Admin\Models\Role;
use Modules\Admin\Models\Setting;
use Modules\Admin\Models\SmtpSetting;

class AdminDatabaseSeeder extends Seeder
{
    /**
     * DDS-6 default roles/permissions - the RBAC foundation for Phase 1.
     * Full role/permission management UI is built in Phase 7 (Admin Panel).
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'Super Admin', 'description' => 'Full platform access, including database maintenance.', 'is_system' => true],
            ['name' => 'Admin', 'description' => 'Manages users, content, and platform settings.', 'is_system' => true],
            ['name' => 'Moderator', 'description' => 'Moderates chat, groups, and reported content.', 'is_system' => true],
            ['name' => 'User', 'description' => 'Standard registered user.', 'is_system' => true],
            ['name' => 'Guest', 'description' => 'Unregistered participant with a time-limited meeting session.', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }

        $permissions = [
            ['permission_key' => 'manage_users', 'permission_name' => 'Manage Users', 'module' => 'admin'],
            ['permission_key' => 'manage_roles', 'permission_name' => 'Manage Roles & Permissions', 'module' => 'admin'],
            ['permission_key' => 'manage_meetings', 'permission_name' => 'Manage Meetings', 'module' => 'meeting'],
            ['permission_key' => 'manage_groups', 'permission_name' => 'Manage Groups', 'module' => 'chat'],
            ['permission_key' => 'manage_files', 'permission_name' => 'Manage Files', 'module' => 'media'],
            ['permission_key' => 'view_analytics', 'permission_name' => 'View Analytics', 'module' => 'analytics'],
            ['permission_key' => 'manage_settings', 'permission_name' => 'Manage Settings', 'module' => 'admin'],
            ['permission_key' => 'manage_backups', 'permission_name' => 'Manage Backups', 'module' => 'archive'],
            ['permission_key' => 'view_audit_logs', 'permission_name' => 'View Audit Logs', 'module' => 'security'],
            ['permission_key' => 'manage_environment', 'permission_name' => 'Manage Environment Variables', 'module' => 'admin'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['permission_key' => $permission['permission_key']], $permission);
        }

        $matrix = [
            'Super Admin' => array_column($permissions, 'permission_key'),
            // The Admin role's own description ("Manages users,
            // content, and platform settings") requires manage_roles/
            // manage_settings too - the original Phase 1 seeder
            // granted neither, which would have made the Admin role
            // unable to do what it's documented to do once Phase 7
            // actually built the endpoints these permissions gate.
            // manage_backups is intentionally withheld here: FR-359
            // requires Super Admin specifically for archive creation/
            // download, enforced by an explicit is_super_admin check
            // in AdminArchiveController regardless of this grant.
            'Admin' => [
                'manage_users', 'manage_roles', 'manage_meetings', 'manage_groups',
                'manage_files', 'view_analytics', 'manage_settings', 'view_audit_logs',
            ],
            'Moderator' => ['manage_groups', 'manage_files'],
            'User' => [],
            'Guest' => [],
        ];

        foreach ($matrix as $roleName => $permissionKeys) {
            $role = Role::where('name', $roleName)->first();
            $permissionIds = Permission::whereIn('permission_key', $permissionKeys)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        $this->seedGeneralSettings();
        BrandingSetting::current();
        SmtpSetting::current();
        ArchiveSetting::current();
        $this->seedFeatureFlags();
        $this->seedRetentionPolicies();
    }

    /**
     * FR-200/FR-222/FR-225/FR-231/FR-232 defaults - the numeric values
     * chosen here are judgment calls where the SRS says only "Admin
     * Configurable" without a documented default (see Phase 7 research
     * notes); the 8-character password minimum is the one hard number
     * SRS/1.md actually specifies.
     */
    private function seedGeneralSettings(): void
    {
        $defaults = [
            ['key' => 'session_timeout_minutes', 'value' => '120', 'category' => 'security', 'type' => 'integer'],
            ['key' => 'login_max_attempts', 'value' => '5', 'category' => 'security', 'type' => 'integer'],
            ['key' => 'login_lockout_minutes', 'value' => '15', 'category' => 'security', 'type' => 'integer'],
            ['key' => 'password_min_length', 'value' => '8', 'category' => 'security', 'type' => 'integer'],
            ['key' => 'password_require_uppercase', 'value' => '1', 'category' => 'security', 'type' => 'boolean'],
            ['key' => 'password_require_number', 'value' => '1', 'category' => 'security', 'type' => 'boolean'],
            ['key' => 'password_require_special', 'value' => '0', 'category' => 'security', 'type' => 'boolean'],
            ['key' => 'api_token_expiry_days', 'value' => '30', 'category' => 'security', 'type' => 'integer'],
            ['key' => 'maintenance_mode_enabled', 'value' => '0', 'category' => 'general', 'type' => 'boolean'],
            ['key' => 'maintenance_message', 'value' => 'Meet is undergoing scheduled maintenance. Please check back soon.', 'category' => 'general', 'type' => 'string'],
            ['key' => 'file_retention_policy', 'value' => '30', 'category' => 'upload', 'type' => 'integer'],
            ['key' => 'file_upload_max_mb', 'value' => '25', 'category' => 'upload', 'type' => 'integer'],
            ['key' => 'delete_for_everyone_window_minutes', 'value' => '60', 'category' => 'chat', 'type' => 'integer'],
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(
                ['setting_key' => $setting['key']],
                ['setting_value' => $setting['value'], 'category' => $setting['category'], 'data_type' => $setting['type']],
            );
        }
    }

    private function seedFeatureFlags(): void
    {
        $flags = [
            ['feature_key' => 'guest_mode', 'label' => 'Guest Meeting Access'],
            ['feature_key' => 'one_view_media', 'label' => 'One View Media'],
            ['feature_key' => 'whiteboard', 'label' => 'Whiteboard'],
            ['feature_key' => 'screen_share', 'label' => 'Screen Sharing'],
            ['feature_key' => 'voice_calls', 'label' => 'Voice Calls'],
            ['feature_key' => 'video_calls', 'label' => 'Video Calls'],
        ];

        foreach ($flags as $flag) {
            FeatureFlag::firstOrCreate(['feature_key' => $flag['feature_key']], $flag);
        }
    }

    /**
     * FR-065 File Expiration options (Never/24h/7d/30d/90d/Custom) applied
     * as one policy row per data category (DDS-7 retention_policies).
     */
    private function seedRetentionPolicies(): void
    {
        $policies = [
            ['category' => RetentionPolicy::MEDIA, 'value' => 30, 'unit' => 'days'],
            ['category' => RetentionPolicy::ACTIVITY_LOGS, 'value' => 90, 'unit' => 'days'],
            ['category' => RetentionPolicy::AUDIT_LOGS, 'value' => null, 'unit' => 'days'],
            ['category' => RetentionPolicy::ARCHIVES, 'value' => 90, 'unit' => 'days'],
        ];

        foreach ($policies as $policy) {
            RetentionPolicy::firstOrCreate(
                ['category' => $policy['category']],
                ['retention_value' => $policy['value'], 'retention_unit' => $policy['unit']],
            );
        }
    }
}
