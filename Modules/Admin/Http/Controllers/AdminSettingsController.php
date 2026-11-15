<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Models\BrandingSetting;
use Modules\Admin\Models\FeatureFlag;
use Modules\Admin\Models\SmtpSetting;
use Modules\Admin\Services\AuditLogger;
use Modules\Admin\Services\SettingsService;

class AdminSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {
    }

    public function general(): JsonResponse
    {
        $this->authorize('manage_settings');

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => $this->settings->all(), 'errors' => null]);
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $this->authorize('manage_settings');
        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        $before = $this->settings->all();

        foreach ($data['settings'] as $key => $value) {
            $existing = \Modules\Admin\Models\Setting::where('setting_key', $key)->first();
            if (! $existing || ! $existing->is_editable) {
                continue;
            }
            $this->settings->set($key, $value, $existing->category, $existing->data_type);
        }

        $this->audit->log($request->user(), 'admin', 'settings_updated', null, null, $before, $this->settings->all());

        return response()->json(['success' => true, 'message' => 'Settings updated.', 'data' => $this->settings->all(), 'errors' => null]);
    }

    public function branding(): JsonResponse
    {
        $this->authorize('manage_settings');

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => BrandingSetting::current(), 'errors' => null]);
    }

    public function updateBranding(Request $request): JsonResponse
    {
        $this->authorize('manage_settings');
        $data = $request->validate([
            'app_name' => ['sometimes', 'string', 'max:100'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'theme_color' => ['sometimes', 'string', 'max:16'],
            'default_accent' => ['sometimes', 'nullable', 'in:brand,' . implode(',', array_keys(\Modules\User\Models\UserPreference::ACCENTS))],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $branding = BrandingSetting::current();
        $old = $branding->only(array_keys($data));
        $branding->update($data);
        $this->audit->log($request->user(), 'admin', 'branding_updated', BrandingSetting::class, $branding->id, $old, $data);

        return response()->json(['success' => true, 'message' => 'Branding updated.', 'data' => $branding, 'errors' => null]);
    }

    public function smtp(): JsonResponse
    {
        $this->authorize('manage_settings');
        $smtp = SmtpSetting::current();

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [...$smtp->only(['host', 'port', 'encryption', 'username', 'from_name', 'from_email', 'last_tested_at', 'last_test_succeeded']), 'password_set' => filled($smtp->password)],
            'errors' => null,
        ]);
    }

    public function updateSmtp(Request $request): JsonResponse
    {
        $this->authorize('manage_settings');
        $data = $request->validate([
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer'],
            'encryption' => ['sometimes', 'in:none,tls,ssl'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_email' => ['sometimes', 'nullable', 'email'],
        ]);

        $smtp = SmtpSetting::current();
        $smtp->update($data);
        // FR-201: credential values (password) never appear in an audit diff.
        $this->audit->log($request->user(), 'admin', 'smtp_updated', SmtpSetting::class, $smtp->id, null, ['fields_changed' => array_keys($data)]);

        return response()->json(['success' => true, 'message' => 'SMTP settings updated.', 'data' => null, 'errors' => null]);
    }

    public function testSmtp(Request $request): JsonResponse
    {
        $this->authorize('manage_settings');
        $smtp = SmtpSetting::current();

        // Only overrides config if this row actually has a host set -
        // a blank row (the correct state for "just use .env") falls
        // through to whatever .env/config/mail.php already has,
        // exactly like real mail sending does (AppServiceProvider).
        // Previously this always overrode config unconditionally, so a
        // blank row nulled out mail.mailers.smtp.host and crashed with
        // a Dsn "host must be of type string, null given" error, even
        // though real mail was working fine off .env at the time.
        $smtp->applyToMailConfig();

        try {
            Mail::raw('This is a test email from your Meet admin panel.', function ($message) use ($request, $smtp) {
                $message->to($request->user()->email)->subject('Meet SMTP test');

                // Same blank-row concern as applyToMailConfig() above:
                // if this row has neither, don't call ->from() at all
                // and let Laravel apply its own configured default
                // (.env's MAIL_FROM_ADDRESS) instead of passing null.
                if ($smtp->from_email || $smtp->username) {
                    $message->from($smtp->from_email ?: $smtp->username, $smtp->from_name);
                }
            });
            $smtp->update(['last_tested_at' => now(), 'last_test_succeeded' => true]);

            return response()->json(['success' => true, 'message' => 'Test email sent.', 'data' => null, 'errors' => null]);
        } catch (\Throwable $e) {
            $smtp->update(['last_tested_at' => now(), 'last_test_succeeded' => false]);

            return response()->json(['success' => false, 'message' => 'Test email failed: '.$e->getMessage(), 'data' => null, 'errors' => null], 422);
        }
    }

    public function featureFlags(): JsonResponse
    {
        $this->authorize('manage_settings');

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => FeatureFlag::all(), 'errors' => null]);
    }

    public function updateFeatureFlag(Request $request, string $key): JsonResponse
    {
        $this->authorize('manage_settings');
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);

        $flag = FeatureFlag::where('feature_key', $key)->firstOrFail();
        $old = $flag->is_enabled;
        $flag->update($data);
        $this->audit->log($request->user(), 'admin', 'feature_flag_changed', FeatureFlag::class, $flag->id, ['is_enabled' => $old], $data);

        return response()->json(['success' => true, 'message' => 'Feature flag updated.', 'data' => $flag, 'errors' => null]);
    }

    /**
     * FR-214 Maintenance Mode - a settings-driven flag checked by
     * App\Http\Middleware\CheckMaintenanceMode, not Laravel's
     * filesystem-based `artisan down`/`up` (which affects the whole
     * app process for every request until explicitly lifted again -
     * a real risk of getting stuck if a later step fails).
     * Administrators bypass automatically per FR-214.
     */
    public function setMaintenanceMode(Request $request): JsonResponse
    {
        $this->authorize('manage_settings');
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'message' => ['sometimes', 'nullable', 'string', 'max:500']]);

        $this->settings->set('maintenance_mode_enabled', $data['enabled'] ? '1' : '0', 'general', 'boolean');

        if (isset($data['message'])) {
            $this->settings->set('maintenance_message', $data['message'], 'general', 'string');
        }

        $this->audit->log($request->user(), 'admin', $data['enabled'] ? 'maintenance_enabled' : 'maintenance_disabled');

        return response()->json(['success' => true, 'message' => 'Maintenance mode updated.', 'data' => null, 'errors' => null]);
    }
}
