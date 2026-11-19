<?php

namespace Modules\Mailbox\Services;

/**
 * Small, defensive RFC 5322 / MIME parser (pure PHP, no mailparse/imap extension needed on cPanel).
 * Limits: 12 levels of nesting, 60 parts. Same parser as Insights by Meet (Modules/Platform/Support/MimeParser). Content is only decoded, never executed or rendered here.
 */
class MimeParser
{
    private int $parts = 0;

    /** @return array{headers: array<string, array<int, string>>, text: string, html: string, attachments: array<int, array{filename: string, mime: string, content: string}>} */
    public function parse(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$headers, $body] = $this->split($raw);
        $result = ['headers' => $headers, 'text' => '', 'html' => '', 'attachments' => []];
        $this->walk($headers, $body, $result, 0);

        return $result;
    }

    public static function header(array $headers, string $name): string
    {
        return $headers[strtolower($name)][0] ?? '';
    }

    public static function decodeHeader(string $value): string
    {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $decoded === false ? $value : $decoded));
    }

    /** "Name <a@b.c>" -> ['a@b.c', 'Name'] */
    public static function address(string $value): array
    {
        $value = self::decodeHeader($value);
        if (preg_match('/^(.*)<([^>]+)>/', $value, $m)) {
            return [strtolower(trim($m[2])), trim($m[1], " \"'")];
        }

        return [strtolower(trim($value, " <>\"'")), ''];
    }

    /** All addresses in a header list (To, Cc, Delivered-To ...). */
    public static function addresses(string $value): array
    {
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i', $value, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    private function split(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head); // unfold
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if ($name === '' || count($headers[$name] ?? []) >= 50) {
                continue;
            }
            $headers[$name][] = trim($value);
        }

        return [$headers, $body];
    }

    private function params(string $value): array
    {
        $parts = explode(';', $value);
        $out = ['_' => strtolower(trim(array_shift($parts)))];
        foreach ($parts as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $k = strtolower(trim($k));
                $v = trim(trim($v), '"');
                if (str_ends_with($k, '*')) { // RFC 2231 filename*=UTF-8''name
                    $k = rtrim($k, '*');
                    $v = rawurldecode(preg_replace("/^[^']*'[^']*'/", '', $v));
                }
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function walk(array $headers, string $body, array &$result, int $depth): void
    {
        if ($depth > 12 || ++$this->parts > 60) {
            return;
        }
        $type = $this->params(self::header($headers, 'content-type') ?: 'text/plain');
        $disp = $this->params(self::header($headers, 'content-disposition'));
        $mime = $type['_'];

        if (str_starts_with($mime, 'multipart/') && !empty($type['boundary'])) {
            $boundary = '--' . $type['boundary'];
            $sections = explode($boundary, $body);
            array_shift($sections); // preamble
            foreach ($sections as $section) {
                if (str_starts_with($section, '--')) {
                    break; // closing boundary
                }
                $section = ltrim($section, "\n");
                [$h, $b] = $this->split($section);
                $this->walk($h, rtrim($b, "\n"), $result, $depth + 1);
            }

            return;
        }

        $content = $this->decodeBody($body, strtolower(self::header($headers, 'content-transfer-encoding')));
        $filename = self::decodeHeader($disp['filename'] ?? ($type['name'] ?? ''));
        $isAttachment = $filename !== '' || ($disp['_'] ?? '') === 'attachment' || !in_array($mime, ['text/plain', 'text/html'], true);

        if ($isAttachment) {
            if ($mime === 'message/rfc822') {
                $mime = 'application/octet-stream';
            }
            $result['attachments'][] = ['filename' => $filename ?: ('part-' . $this->parts), 'mime' => $mime, 'content' => $content];

            return;
        }

        $charset = strtoupper($type['charset'] ?? 'UTF-8');
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
            $content = $converted === false ? $content : $converted;
        }
        $content = mb_scrub($content, 'UTF-8');
        if ($mime === 'text/html' && $result['html'] === '') {
            $result['html'] = $content;
        } elseif ($mime === 'text/plain' && $result['text'] === '') {
            $result['text'] = $content;
        }
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
