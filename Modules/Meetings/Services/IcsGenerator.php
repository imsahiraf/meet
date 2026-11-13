<?php

namespace Modules\Meetings\Services;

use Illuminate\Support\Carbon;

/**
 * FR-167 - minimal RFC5545 (.ics) VCALENDAR/VEVENT generator. No
 * external package is used since a single-event invite is a handful
 * of well-defined lines; recurrence/timezone databases (VTIMEZONE)
 * are out of scope for v1 - times are emitted in UTC (a "Z"-suffixed
 * DTSTART/DTEND), which every calendar client renders correctly
 * regardless of the organizer's local timezone.
 */
class IcsGenerator
{
    public static function forMeeting(
        string $uid,
        string $title,
        ?string $description,
        Carbon $start,
        Carbon $end,
        string $organizerEmail,
        string $organizerName,
        string $joinUrl,
        ?string $attendeeEmail = null,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Meet//Meetings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:'.$uid.'@meet',
            'SEQUENCE:0',
            'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:'.$start->clone()->utc()->format('Ymd\THis\Z'),
            'DTEND:'.$end->clone()->utc()->format('Ymd\THis\Z'),
            'SUMMARY:'.self::escape($title),
            'DESCRIPTION:'.self::escape(($description ? $description."\\n\\n" : '').'Join: '.$joinUrl),
            'ORGANIZER;CN='.self::escape($organizerName).':mailto:'.$organizerEmail,
        ];

        if ($attendeeEmail) {
            $lines[] = 'ATTENDEE;RSVP=TRUE:mailto:'.$attendeeEmail;
        }

        $lines[] = 'URL:'.$joinUrl;
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(["\\", ',', ';', "\n"], ['\\\\', '\\,', '\\;', '\\n'], $value);
    }
}
