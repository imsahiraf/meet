<?php

namespace Modules\Mailbox\Services;

use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;

/**
 * Personal Meet addresses: <chosen name><suffix>@<domain>, e.g. rahul.user@example.com. The suffix keeps them apart from the
 * company's own cPanel mailboxes on the same domain (cPanel delivers those itself; the rest reach Meet through the catch-all pipe).
 */
class Addresses
{
    public const RESERVED = ['admin', 'administrator', 'root', 'postmaster', 'abuse', 'hostmaster', 'webmaster', 'support', 'info', 'billing',
        'security', 'noreply', 'no-reply', 'mailer-daemon', 'legal', 'privacy', 'sales', 'help', 'meet', 'meet', 'example'];

    /** Own addresses per person: the first one and two aliases. */
    public const MAX = 3;

    public static function domain(): string
    {
        return strtolower(trim((string) config('mailbox.domain')));
    }

    /** Other domains whose mail reaches the same mailboxes (not the main domain itself). */
    public static function aliasDomains(): array
    {
        $main = self::domain();
        $list = array_map(fn ($d) => strtolower(trim($d)), explode(',', (string) config('mailbox.alias_domains', '')));

        return $main === '' ? [] : array_values(array_unique(array_filter($list, fn ($d) => $d !== '' && $d !== $main && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d))));
    }

    /** name.user@example.net => name.user@example.com; null when the address is on none of our domains. */
    public static function mailboxFor(string $addr): ?string
    {
        $addr = strtolower(trim($addr));
        $main = self::domain();
        if ($main === '' || !str_contains($addr, '@')) {
            return null;
        }
        [$local, $dom] = explode('@', $addr, 2);
        if ($dom === $main || in_array($dom, self::aliasDomains(), true)) {
            return $local . '@' . $main;
        }

        return null;
    }

    public static function suffix(): string
    {
        return strtolower((string) config('mailbox.local_suffix', '.user'));
    }

    /** "Rahul.K" => rahul.k.user@example.com (a name already ending with the suffix is not doubled). */
    public static function make(string $choice): string
    {
        $choice = strtolower(trim($choice));
        $suffix = self::suffix();
        if ($suffix !== '' && str_ends_with($choice, $suffix)) {
            $choice = substr($choice, 0, -strlen($suffix));
        }

        return $choice . $suffix . '@' . self::domain();
    }

    public static function create(User $user, string $choice, int $max = self::MAX): MailAccount
    {
        if (self::domain() === '') {
            throw ValidationException::withMessages(['local' => __('Own addresses are not switched on yet.')]);
        }
        $name = strtolower(trim($choice));
        $suffix = self::suffix();
        if ($suffix !== '' && str_ends_with($name, $suffix)) {
            $name = substr($name, 0, -strlen($suffix));
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9._-]{1,28}[a-z0-9])?$/', $name) || strlen($name) < 3) {
            throw ValidationException::withMessages(['local' => __('Use 3 to 30 letters or numbers (dots, - and _ allowed inside).')]);
        }
        // one address plus up to two more (aliases): all reach the same person, each can be the sender
        if (MailAccount::where('user_id', $user->id)->where('kind', 'local')->count() >= $max) {
            throw ValidationException::withMessages(['local' => $max === 1 ? __('You already have a Meet address.') : __('You already have :n Meet addresses.', ['n' => $max])]);
        }
        $email = self::make($name);
        if (in_array($name, self::RESERVED, true) || MailAccount::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['local' => __('This address is taken. Try another one.')]);
        }

        return MailAccount::create(['user_id' => $user->id, 'kind' => 'local', 'email' => $email, 'display_name' => $user->full_name,
            'is_default' => !MailAccount::where('user_id', $user->id)->exists()]);
    }
}
