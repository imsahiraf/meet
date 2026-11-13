<?php

namespace Modules\Meetings\Services;

/** Writes the summary of a meeting from its transcript ("Name: line" per line). */
interface MeetingSummarizer
{
    public function summarize(string $title, string $transcript): string;
}
