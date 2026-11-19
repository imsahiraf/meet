<?php

namespace Modules\Mailbox\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use RuntimeException;

/**
 * "Help me write" in the compose window (Insights by Meet Mail Hub parity, 2026-11-11): fix spelling, make the text polite, shorter or
 * formal. Uses the same AI key and client as the meeting notes (MEET_AI_KEY). Only the person's own new text is changed; a quoted
 * earlier message stays exactly as it was.
 */
class MailWriter
{
    public const MODES = [
        'fix' => 'Correct the spelling, grammar and punctuation only. Keep the words, tone and meaning.',
        'polite' => 'Make it polite and friendly, same meaning.',
        'shorter' => 'Make it shorter and clearer, keep every fact, name, number and date.',
        'formal' => 'Make it formal business English, same meaning.',
    ];

    public static function rewrite(string $mode, string $html): string
    {
        $key = (string) config('meetings.ai.key');
        if ($key === '') {
            throw new RuntimeException(__('Writing help needs the AI key (MEET_AI_KEY). Ask your administrator.'));
        }
        $parts = preg_split('#(<blockquote[\s\S]*$)#i', $html, 2, PREG_SPLIT_DELIM_CAPTURE);
        $mine = $parts[0];
        $quoted = $parts[1] ?? '';
        if (trim(strip_tags($mine)) === '') {
            throw new RuntimeException(__('Write something first.'));
        }
        try {
            $message = (new Client(apiKey: $key))->messages->create(
                model: (string) config('meetings.ai.model', 'claude-opus-5-5'),
                maxTokens: 4000,
                system: 'You edit e-mails for small businesses. Return only the e-mail body as simple HTML (p, br, strong, em, u, ul, ol, li, a) - no code fences, no comments, no subject line. No long dashes.',
                messages: [['role' => 'user', 'content' => self::MODES[$mode] . "\nE-mail:\n" . $mine]],
            );
        } catch (APIStatusException $e) {
            throw new RuntimeException(__('The AI service answered with an error.'), 0, $e);
        } catch (APIConnectionException $e) {
            throw new RuntimeException(__('The AI service could not be reached.'), 0, $e);
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = str_replace(["\u{2014}", "\u{2013}"], '-', trim(preg_replace('/^```[a-z]*\s*|\s*```$/i', '', trim($text))));
        if ($text === '') {
            throw new RuntimeException(__('The AI service gave an empty answer.'));
        }

        return HtmlSanitizer::clean($text) . $quoted;
    }
}
