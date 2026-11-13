<?php

namespace Modules\Mailbox\Services;

/** Calendar invites (.ics, text/calendar) in a mail: the events they hold, for the card on the message page. */
class Ics
{
    /** @return array<int, array{title: string, start: ?string, end: ?string, where: string, organizer: string, method: string}> */
    public static function events(string $ics): array
    {
        $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics)); // unfold long lines (RFC 5545 3.1)
        $method = preg_match('/^METHOD:(.+)$/m', $ics, $mm) ? strtoupper(trim($mm[1])) : '';
        $out = [];
        if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks)) {
            return [];
        }
        foreach (array_slice($blocks[1], 0, 5) as $b) {
            $get = function (string $key) use ($b): array {
                return preg_match('/^' . $key . '((?:;[^:\n]*)?):(.*)$/m', $b, $m) ? [$m[1], trim($m[2])] : ['', ''];
            };
            $text = fn (string $v) => mb_substr(str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v), 0, 300);
            $out[] = ['title' => $text($get('SUMMARY')[1]) ?: __('(no title)'), 'start' => self::time(...$get('DTSTART')), 'end' => self::time(...$get('DTEND')),
                'where' => $text($get('LOCATION')[1]), 'organizer' => preg_replace('/^mailto:/i', '', $get('ORGANIZER')[1]), 'method' => $method,
                // what an answer (METHOD:REPLY) has to repeat
                'uid' => $get('UID')[1], 'sequence' => (int) $get('SEQUENCE')[1], 'raw_start' => $get('DTSTART'), 'raw_end' => $get('DTEND'), 'raw_summary' => $get('SUMMARY')[1]];
        }

        return $out;
    }

    /** The answer to an invite (RFC 5546 REPLY): accepted / tentative / declined by $email. */
    public static function reply(array $ev, string $email, string $name, string $answer): string
    {
        $stat = ['accept' => 'ACCEPTED', 'tentative' => 'TENTATIVE', 'decline' => 'DECLINED'][$answer];
        $clean = fn (string $v) => str_replace(["\r", "\n"], ' ', $v);
        $lines = ['BEGIN:VCALENDAR', 'PRODID:-//Meet//Meet Mail//EN', 'VERSION:2.0', 'METHOD:REPLY', 'BEGIN:VEVENT', 'UID:' . $clean($ev['uid']), 'DTSTAMP:' . gmdate('Ymd\\THis\\Z'),
            'DTSTART' . $ev['raw_start'][0] . ':' . $ev['raw_start'][1]];
        if ($ev['raw_end'][1] !== '') {
            $lines[] = 'DTEND' . $ev['raw_end'][0] . ':' . $ev['raw_end'][1];
        }
        array_push($lines, 'SEQUENCE:' . (int) $ev['sequence'], 'SUMMARY:' . $clean($ev['raw_summary']), 'ORGANIZER:mailto:' . $clean($ev['organizer']),
            'ATTENDEE;PARTSTAT=' . $stat . ';CN=' . str_replace([';', ':', ','], ' ', $clean($name)) . ':mailto:' . $clean($email), 'END:VEVENT', 'END:VCALENDAR');

        return implode("\r\n", $lines) . "\r\n";
    }

    /** "20261110T103000Z", "20261110T103000" with TZID, or a date "20261110" -> "10 Nov 2026, 16:00" in the app's time zone. */
    private static function time(string $params, string $v): ?string
    {
        if (!preg_match('/^(\d{8})(T(\d{6})(Z)?)?$/', $v, $m)) {
            return null;
        }
        try {
            if (empty($m[2])) {
                return \Illuminate\Support\Carbon::createFromFormat('Ymd', $m[1])->format('d M Y');
            }
            $tz = !empty($m[4]) ? 'UTC' : (preg_match('/TZID=([^;:]+)/', $params, $t) && in_array($t[1], timezone_identifiers_list(), true) ? $t[1] : config('app.timezone'));

            return \Illuminate\Support\Carbon::createFromFormat('Ymd His', $m[1] . ' ' . $m[3], $tz)->setTimezone(config('app.timezone'))->format('d M Y, H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
