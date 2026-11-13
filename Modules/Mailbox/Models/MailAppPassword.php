<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** A password for one phone mail app (ActiveSync). Only its hash is kept; the plain text is shown once. */
class MailAppPassword extends Model
{

    protected $guarded = ['id'];

    protected $hidden = ['hash'];

    protected $casts = ['last_used_at' => 'datetime'];

    /** @return array{0: self, 1: string} the row and the plain password (4 groups of 4 letters, easy to type on a phone) */
    public static function make(int $userId, string $name): array
    {
        $plain = implode('-', str_split(Str::lower(Str::random(16)), 4));
        $plain = strtr($plain, ['0' => 'k', 'o' => 'm', '1' => 'n', 'l' => 'p', 'i' => 'r']); // no look-alike characters
        $row = self::create(['user_id' => $userId, 'name' => mb_substr($name, 0, 80), 'hash' => Hash::make($plain), 'hint' => substr($plain, -4)]);

        return [$row, $plain];
    }

    /** The user's app password that matches, or null. Spaces and dashes are ignored (phones often add them). */
    public static function check(int $userId, string $password): ?self
    {
        $clean = strtolower(preg_replace('/[\s-]+/', '', $password));
        if (strlen($clean) !== 16) {
            return null;
        }
        $pretty = implode('-', str_split($clean, 4));
        foreach (self::where('user_id', $userId)->get() as $row) {
            if (Hash::check($pretty, $row->hash)) {
                return $row;
            }
        }

        return null;
    }
}
