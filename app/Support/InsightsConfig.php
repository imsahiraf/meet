<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Settings the super admin of Insights by Meet keeps in one place (domains, mail domain, brand): pulled signed with the
 * shared secret (INSIGHTS_SSO_SECRET), cached for an hour, applied on boot. Without the secret nothing changes.
 */
class InsightsConfig
{
    public static function apply(): void
    {
        $secret = (string) config('services.insights.sso_secret');
        if ($secret === '' || app()->runningUnitTests()) {
            return;
        }
        try {
            $c = Cache::remember('insights.central_config', 3600, function () use ($secret) {
                $time = (string) time();
                $r = Http::timeout(3)->withHeaders(['X-Meet-Time' => $time, 'X-Meet-Sign' => hash_hmac('sha256', $time, $secret)])
                    ->get(rtrim((string) config('services.insights.url', 'https://github.com/imsahiraf/meet'), '/') . '/api/platform/config/meet');

                return $r->ok() && is_array($r->json('root_domains')) ? $r->json() : [];
            });
            if (!$c) {
                Cache::put('insights.central_config', [], 600); // Insights unreachable: try again in 10 minutes
                return;
            }
        } catch (\Throwable) {
            return;
        }
        config(['services.tools.hosts' => implode(',', $c['root_domains']), 'services.insights.central' => $c]);
    }
}
