<?php

namespace Modules\Mailbox\Services;

/**
 * Search words like in Gmail: from:, to:, subject:, has:attachment, is:unread / is:read / is:starred, label:, before:YYYY-MM-DD,
 * after:YYYY-MM-DD, in:<folder> (in:anywhere = every folder but Trash and Spam). The rest is matched as text.
 * Returns the folder asked for with in: (or null).
 */
class SearchWords
{
    public static function apply($q, string $text, int $userId): ?string
    {
        $folder = null;
        foreach (preg_split('/\s+(?=(?:[^"]*"[^"]*")*[^"]*$)/', trim($text)) as $tok) {
            if ($tok === '') {
                continue;
            }
            if (!preg_match('/^(from|to|subject|has|is|label|before|after|in):(.+)$/i', $tok, $m)) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($tok, '"')) . '%';
                $q->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('from_email', 'like', $like)->orWhere('from_name', 'like', $like)
                    ->orWhere('snippet', 'like', $like)->orWhere('body_text', 'like', $like));
                continue;
            }
            $v = trim($m[2], '"');
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $v) . '%';
            match (strtolower($m[1])) {
                'from' => $q->where(fn ($w) => $w->where('from_email', 'like', $like)->orWhere('from_name', 'like', $like)),
                'to' => $q->where(fn ($w) => $w->where('to', 'like', $like)->orWhere('cc', 'like', $like)),
                'subject' => $q->where('subject', 'like', $like),
                'has' => strtolower($v) === 'attachment' ? $q->where('has_attachments', true) : null,
                'is' => match (strtolower($v)) { 'unread' => $q->where('seen', false), 'read' => $q->where('seen', true), 'starred' => $q->where('starred', true), default => null },
                'label' => $q->whereHas('labels', fn ($l) => $l->where('name', $v)->where('user_id', $userId)),
                'before' => strtotime($v) ? $q->where('sent_at', '<', date('Y-m-d', strtotime($v))) : null,
                'after' => strtotime($v) ? $q->where('sent_at', '>=', date('Y-m-d', strtotime($v))) : null,
                'in' => $folder = strtolower($v),
            };
        }

        return $folder;
    }
}
