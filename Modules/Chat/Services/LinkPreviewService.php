<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp-style "first URL in a message gets a title/description/image
 * card" preview. Fetches the URL server-side (a client-side fetch would
 * hit CORS on almost every real site) - which means this is exactly the
 * kind of user-supplied-URL server fetch that needs real SSRF defences,
 * not just "does it look like a URL":
 *
 * - Only http(s), and the host must resolve to a genuinely public IP
 *   (isFetchable() below) - blocks the obvious "preview http://
 *   localhost/admin" or "preview http://169.254.169.254/..." (cloud
 *   metadata endpoint) probes.
 * - Redirects are never followed at all, specifically so an
 *   initially-public URL can't 302 its way to a private one past the
 *   check above.
 * - The IP validated above is pinned via cURL's own DNS-override option
 *   for the actual request, so a second, independent DNS lookup at
 *   connect time (attacker-controlled, low-TTL "DNS rebinding") can't
 *   silently resolve somewhere different from what was just validated.
 */
class LinkPreviewService
{
    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 7;
    private const MAX_BODY_BYTES = 512 * 1024;

    public function preview(string $url): ?array
    {
        $url = trim($url);

        $host = $this->resolvableHost($url);
        if (! $host) {
            return null;
        }

        return Cache::remember('link_preview:'.md5($url), self::CACHE_TTL_SECONDS, function () use ($url, $host) {
            return $this->fetch($url, $host['host'], $host['ip']);
        });
    }

    /**
     * @return array{host: string, ip: string}|null
     */
    private function resolvableHost(string $url): ?array
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        $host = $parts['host'];
        $isLiteralIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ip = $isLiteralIp ? $host : gethostbyname($host);

        if (! $isLiteralIp && $ip === $host) {
            // gethostbyname() returns the input unchanged when it can't
            // resolve at all - genuinely unusable either way.
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return ['host' => $host, 'ip' => $ip];
    }

    private function fetch(string $url, string $host, string $ip): ?array
    {
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:80:{$ip}", "{$host}:443:{$ip}"],
                ],
            ])
                ->timeout(5)
                ->connectTimeout(3)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MeetLinkPreview/1.0)'])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contentType = (string) $response->header('Content-Type');
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return null;
        }

        $html = substr($response->body(), 0, self::MAX_BODY_BYTES);

        $title = $this->extractMeta($html, 'og:title') ?? $this->extractTitleTag($html);
        if (! $title) {
            return null;
        }

        $description = $this->extractMeta($html, 'og:description') ?? $this->extractMeta($html, 'description');
        $image = $this->extractMeta($html, 'og:image');

        return [
            'url' => $url,
            'title' => mb_substr(trim($title), 0, 200),
            'description' => $description ? mb_substr(trim($description), 0, 300) : null,
            'image' => ($image && filter_var($image, FILTER_VALIDATE_URL)) ? $image : null,
        ];
    }

    private function extractMeta(string $html, string $property): ?string
    {
        $quotedProperty = preg_quote($property, '/');

        if (preg_match('/<meta[^>]+(?:property|name)=["\']'.$quotedProperty.'["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        // Attribute order isn't guaranteed - content before property/name.
        if (preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:property|name)=["\']'.$quotedProperty.'["\'][^>]*>/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }

        return null;
    }

    private function extractTitleTag(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES);
        }

        return null;
    }
}
