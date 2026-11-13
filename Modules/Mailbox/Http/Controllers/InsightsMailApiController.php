<?php

namespace Modules\Mailbox\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Http\Controllers\InsightsSsoController;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Services\Addresses;
use Modules\Mailbox\Services\DriveArchive;
use Modules\Mailbox\Services\Mailbox;

/**
 * Meet Insights reads and sends the person's Meet address (name.user@example.com) in its Mail Hub, and (owner 2026-11-11: the same
 * mail in both places) the accounts the person added here (IMAP, Gmail). Accounts that came from Insights (kind "insights") are not
 * offered back. Calls without "account" mean the Meet address (older Insights versions).
 * Every call carries "Authorization: Bearer <token>" signed by Insights with the shared INSIGHTS_SSO_SECRET
 * (same format as the sign-in link), aud "meet", scope "mail", valid 5 minutes. Only the person's own mailbox is reachable.
 */
class InsightsMailApiController extends Controller
{
    private function user(Request $request): User
    {
        $secret = (string) config('services.insights.sso_secret');
        $token = (string) $request->bearerToken();
        $payload = $secret !== '' ? InsightsSsoController::verify($token, (string) config('services.insights.audience', 'meet'), $secret) : null;
        if (!$payload || ($payload['iss'] ?? '') !== 'insights' || ($payload['scope'] ?? '') !== 'mail' || (int) ($payload['exp'] ?? 0) > time() + 600) {
            Log::warning('Insights mail API rejected', ['ip' => $request->ip()]);
            abort(401, 'Invalid token');
        }
        $user = InsightsSsoController::userFor($payload);
        abort_unless($user && ($user->status ?? 'active') === 'active', 404, 'No Meet account');
        if (isset($payload['mail_mb'])) {
            InsightsSsoController::applyMailPlan($user->id, $payload);
        }

        return $user;
    }

    /** Kinds Insights may read: the Meet address and the accounts added here. */
    public const KINDS = ['local', 'imap', 'google'];

    private function account(User $user, ?int $id = null): ?MailAccount
    {
        return $id ? MailAccount::where('user_id', $user->id)->whereIn('kind', self::KINDS)->find($id)
            : MailAccount::where('user_id', $user->id)->where('kind', 'local')->first();
    }

    /** The person's accounts Insights may show: the Meet address ("meet") and the ones added here ("added"). */
    public function accounts(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['accounts' => MailAccount::where('user_id', $user->id)->whereIn('kind', self::KINDS)->orderByRaw("kind = 'local' desc")->orderBy('email')->get()
            ->map(fn ($a) => ['id' => $a->id, 'email' => $a->email, 'name' => $a->display_name ?: $user->full_name, 'kind' => $a->kind === 'local' ? 'meet' : 'added'])->values()]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $a = $this->account($user);

        return response()->json(['email' => $a?->email, 'name' => $user->full_name, 'domain' => Addresses::domain(), 'suffix' => Addresses::suffix(),
            'quota' => Mailbox::quota($user->id)]);
    }

    public function address(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['choice' => 'required|string|max:40']);
        try {
            $a = Addresses::create($user, $data['choice'], 1); // the Insights Mail Hub shows one personal address
        } catch (ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json(['email' => $a->email], 201);
    }

    /** New messages of the address after the given id (raw e-mail, base64), oldest first. */
    public function messages(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $a = $this->account($user, $request->integer('account') ?: null);
        if (!$a && $request->integer('account')) {
            abort(404, 'No such account');
        }
        if (!$a) {
            return response()->json(['messages' => [], 'last' => 0]);
        }
        $after = max(0, (int) $request->query('after', 0));
        $limit = min(100, max(1, (int) $request->query('limit', 50)));
        $rows = MailMessage::where('account_id', $a->id)->where('id', '>', $after)->whereNotIn('folder', ['drafts', 'trash'])
            ->with('attachments')->orderBy('id')->limit($limit)->get();
        $out = [];
        foreach ($rows as $m) {
            try {
                $raw = $m->drive_file_id ? DriveArchive::loadRaw($m) : DriveArchive::raw($m);
            } catch (\Throwable) {
                continue; // archived to Drive and Drive is not reachable now: skipped, still readable in Meet
            }
            $out[] = ['id' => $m->id, 'folder' => $m->folder, 'seen' => (bool) $m->seen, 'starred' => (bool) $m->starred, 'raw' => base64_encode($raw)];
        }

        return response()->json(['messages' => $out, 'last' => $rows->max('id') ?? $after]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $a = $this->account($user, $request->integer('account') ?: null);
        abort_unless($a, 404, $request->integer('account') ? 'No such account' : 'No Meet address yet');
        $data = $request->validate([
            'to' => 'array|max:50', 'to.*' => 'email', 'cc' => 'array|max:50', 'cc.*' => 'email', 'bcc' => 'array|max:50', 'bcc.*' => 'email',
            'subject' => 'nullable|string|max:300', 'html' => 'nullable|string|max:2000000',
            'attachments' => 'array|max:20', 'attachments.*.filename' => 'required|string|max:190', 'attachments.*.mime' => 'nullable|string|max:120',
            'attachments.*.content' => 'required|string',
        ]);
        if (empty($data['to']) && empty($data['cc']) && empty($data['bcc'])) {
            return response()->json(['error' => 'Add at least one recipient.'], 422);
        }
        $files = array_map(fn ($f) => ['filename' => $f['filename'], 'mime' => $f['mime'] ?? null, 'content' => base64_decode($f['content'])], $data['attachments'] ?? []);
        if (array_sum(array_map(fn ($f) => strlen($f['content']), $files)) > (int) config('mailbox.max_message_mb', 20) * 1048576) {
            return response()->json(['error' => 'Attachments are too large.'], 422);
        }
        $html = \Modules\Mailbox\Services\HtmlSanitizer::clean((string) ($data['html'] ?? ''));
        $copy = Mailbox::send($a, $data['to'] ?? [], $data['cc'] ?? [], $data['bcc'] ?? [], (string) ($data['subject'] ?? ''), $html, $files);
        $copy->load('attachments');

        return response()->json(['id' => $copy->id, 'raw' => base64_encode(DriveArchive::raw($copy))], 201);
    }
}
