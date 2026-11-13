<?php

namespace Modules\Chat\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationKey;

/**
 * Issues/rotates the per-conversation AES-256-GCM key that E2E messages
 * are encrypted under. The app generates and holds this key itself
 * (encrypted at rest with the app's own key - Crypt, same pattern as
 * View-Once media) - a genuinely different, deliberately simpler trust
 * model than a zero-knowledge scheme, per direct instruction: no
 * per-user keypair, no password-derived unlock, no per-recipient
 * wrapping. Any current, authorized member can fetch the raw key
 * on request; access is enforced live (conversation membership) at
 * fetch time rather than baked into who a key was wrapped for.
 */
class ConversationEncryptionService
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {
    }

    private function activeMemberIds(Conversation $conversation): array
    {
        return $conversation->members()->whereNull('left_at')->pluck('user_id')->sort()->values()->all();
    }

    public function isEligible(Conversation $conversation): bool
    {
        return count($this->activeMemberIds($conversation)) <= config('chat.e2e_max_group_members');
    }

    /**
     * Everything a client needs to know about this conversation's
     * encryption state - auto-issuing a fresh key epoch first if the
     * conversation is eligible but has none yet, or if membership has
     * drifted since the last epoch was issued (someone joined/left),
     * so the caller never has to make a separate "please issue a key"
     * request - this is the one call that does it all, transparently.
     */
    public function status(User $user, Conversation $conversation): array
    {
        $this->conversations->assertMember($user, $conversation);

        $activeMemberIds = $this->activeMemberIds($conversation);
        $eligible = count($activeMemberIds) <= config('chat.e2e_max_group_members');

        $latestKey = ConversationKey::where('conversation_id', $conversation->id)->latest('key_version')->first();
        $needsRotation = $eligible && (! $latestKey || $latestKey->issued_for_member_ids !== $activeMemberIds);

        if ($needsRotation) {
            $latestKey = $this->issueKey($conversation, $activeMemberIds);
        }

        return [
            'e2e_enabled' => (bool) $conversation->fresh()->e2e_enabled,
            'eligible' => $eligible,
            'max_group_members' => config('chat.e2e_max_group_members'),
            'current_key_version' => $latestKey?->key_version,
        ];
    }

    /**
     * Issues a brand-new key epoch for exactly the conversation's
     * currently-active members - called automatically from status()
     * whenever needed, never directly by a client. Concurrent callers
     * racing to issue the same version are resolved by the unique
     * (conversation_id, key_version) constraint; the loser's attempt is
     * simply discarded in favor of the winner's (both would have
     * generated an equally valid key for the same member set).
     */
    private function issueKey(Conversation $conversation, array $activeMemberIds): ConversationKey
    {
        if (count($activeMemberIds) > config('chat.e2e_max_group_members')) {
            throw ValidationException::withMessages([
                'conversation' => ['This conversation has too many members for end-to-end encryption.'],
            ]);
        }

        $nextVersion = (ConversationKey::where('conversation_id', $conversation->id)->max('key_version') ?? 0) + 1;

        try {
            return DB::transaction(function () use ($conversation, $activeMemberIds, $nextVersion) {
                $key = ConversationKey::create([
                    'conversation_id' => $conversation->id,
                    'key_version' => $nextVersion,
                    'key_material' => Crypt::encryptString(base64_encode(random_bytes(32))),
                    'issued_for_member_ids' => $activeMemberIds,
                ]);

                $conversation->update(['e2e_enabled' => true]);

                return $key;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = ConversationKey::where('conversation_id', $conversation->id)
                ->where('key_version', $nextVersion)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * The raw (base64) AES key for a specific epoch - a message
     * rendered from scrollback may reference an older epoch than the
     * conversation's current one (rotation doesn't invalidate history),
     * so this always takes an explicit version rather than "the
     * latest." Any *current* active member can fetch any past epoch's
     * key - rotation only ever protects *future* epochs from a removed
     * member, matching what it always protected under the old wrapped-
     * key model too (a removed member never gets a wrapped copy of a
     * later epoch, but nothing ever revoked their access to earlier
     * ones they already had).
     */
    public function keyMaterialForVersion(User $user, Conversation $conversation, int $keyVersion): ?string
    {
        $this->conversations->assertMember($user, $conversation);

        $key = ConversationKey::where('conversation_id', $conversation->id)
            ->where('key_version', $keyVersion)
            ->first();

        return $key ? Crypt::decryptString($key->key_material) : null;
    }

    /**
     * Server-side decrypt of an E2E text message body, for building a
     * real push-notification preview (see MessageService::
     * pushToRecipients()). This is only possible at all because of this
     * app's deliberately non-zero-knowledge trust model (see this
     * class's own docblock) - the server holds the same key material a
     * client would fetch, so it can read the plaintext exactly as any
     * current member's browser does, without needing anything from the
     * client. $asMember just needs to BE a genuine current member (the
     * sender always is); this never grants any access beyond what that
     * member's own client already has.
     */
    public function decryptForPreview(Conversation $conversation, User $asMember, int $keyVersion, string $cipherBase64, string $ivBase64): ?string
    {
        $keyMaterialBase64 = $this->keyMaterialForVersion($asMember, $conversation, $keyVersion);

        if (! $keyMaterialBase64) {
            return null;
        }

        $key = base64_decode($keyMaterialBase64, true);
        $ciphertext = base64_decode($cipherBase64, true);
        $iv = base64_decode($ivBase64, true);

        // WebCrypto's AES-GCM output is ciphertext with the 16-byte auth
        // tag appended - openssl_decrypt() wants that tag passed
        // separately, not inline.
        if ($key === false || $ciphertext === false || $iv === false || strlen($ciphertext) < 17) {
            return null;
        }

        $tag = substr($ciphertext, -16);
        $data = substr($ciphertext, 0, -16);

        $plaintext = openssl_decrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }
}
