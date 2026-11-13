<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\Http;
use Modules\Mailbox\Models\MailContact;
use Modules\Mailbox\Models\MailSetting;
use RuntimeException;

/**
 * Google for Meet Mail (free for every user): OAuth with offline access, Gmail over IMAP / SMTP (XOAUTH2),
 * Drive (only files this app creates: the "Meet Mail" folder) and Contacts (read only). Uses the same OAuth client
 * as "Sign in with Google" (services.google.client_id / client_secret).
 */
class GoogleApi
{
    public static function configured(): bool
    {
        return (string) config('services.google.client_id') !== '' && (string) config('services.google.client_secret') !== '';
    }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => config('mailbox.google.redirect'),
            'response_type' => 'code', 'scope' => implode(' ', config('mailbox.google.scopes')),
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]);
    }

    /** Exchange the callback code; returns [token array, google e-mail]. */
    public static function exchange(string $code): array
    {
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('mailbox.google.redirect'), 'grant_type' => 'authorization_code',
        ]);
        if (!$r->successful() || !$r->json('access_token')) {
            throw new RuntimeException('Google did not accept the sign-in (' . ($r->json('error_description') ?: $r->status()) . ')');
        }
        $token = ['access_token' => $r->json('access_token'), 'refresh_token' => $r->json('refresh_token'), 'expires_at' => time() + (int) $r->json('expires_in', 3600) - 60];
        $email = Http::withToken($token['access_token'])->timeout(20)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');

        return [$token, strtolower((string) $email)];
    }

    /** A valid access token (refreshed when needed). */
    public static function accessToken(MailSetting $s): string
    {
        $t = $s->google_token ?? [];
        if (empty($t['access_token'])) {
            throw new RuntimeException('Google is not connected');
        }
        if (($t['expires_at'] ?? 0) > time()) {
            return $t['access_token'];
        }
        if (empty($t['refresh_token'])) {
            throw new RuntimeException('Google access expired - connect Google again');
        }
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token',
        ]);
        if (!$r->successful()) {
            throw new RuntimeException('Google access was removed - connect Google again');
        }
        $t['access_token'] = $r->json('access_token');
        $t['expires_at'] = time() + (int) $r->json('expires_in', 3600) - 60;
        $s->google_token = $t;
        $s->save();

        return $t['access_token'];
    }

    // ---------------- Drive ----------------

    public static function driveFolder(MailSetting $s): string
    {
        if ($s->drive_folder_id) {
            return $s->drive_folder_id;
        }
        $r = Http::withToken(self::accessToken($s))->timeout(30)->post('https://www.googleapis.com/drive/v3/files', [
            'name' => 'Meet Mail', 'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $id = (string) $r->throw()->json('id');
        $s->drive_folder_id = $id;
        $s->save();

        return $id;
    }

    public static function driveUpload(MailSetting $s, string $name, string $content, string $mime = 'message/rfc822'): string
    {
        $boundary = 'zx' . bin2hex(random_bytes(8));
        $meta = json_encode(['name' => $name, 'parents' => [self::driveFolder($s)], 'mimeType' => $mime]);
        $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$boundary\r\nContent-Type: $mime\r\n\r\n$content\r\n--$boundary--";
        $r = Http::withToken(self::accessToken($s))->timeout(120)->withBody($body, "multipart/related; boundary=$boundary")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        return (string) $r->throw()->json('id');
    }

    public static function driveDownload(MailSetting $s, string $fileId): string
    {
        return Http::withToken(self::accessToken($s))->timeout(120)->get("https://www.googleapis.com/drive/v3/files/$fileId", ['alt' => 'media'])->throw()->body();
    }

    // ---------------- Contacts ----------------

    /** Google contacts with an e-mail address into the user's address book (compose suggestions). */
    public static function syncContacts(MailSetting $s): int
    {
        $n = 0;
        $page = null;
        do {
            $r = Http::withToken(self::accessToken($s))->timeout(30)->get('https://people.googleapis.com/v1/people/me/connections', array_filter([
                'personFields' => 'names,emailAddresses,phoneNumbers', 'pageSize' => 1000, 'pageToken' => $page,
            ]))->throw();
            foreach ($r->json('connections', []) as $p) {
                $name = $p['names'][0]['displayName'] ?? null;
                $phone = $p['phoneNumbers'][0]['value'] ?? null;
                foreach ($p['emailAddresses'] ?? [] as $e) {
                    $email = strtolower(trim($e['value'] ?? ''));
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    MailContact::updateOrCreate(['user_id' => $s->user_id, 'email' => $email], ['name' => $name, 'phone' => $phone, 'source' => 'google']);
                    $n++;
                }
            }
            $page = $r->json('nextPageToken');
        } while ($page && $n < 20000);

        return $n;
    }
}
