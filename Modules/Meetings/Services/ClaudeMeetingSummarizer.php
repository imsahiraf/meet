<?php

namespace Modules\Meetings\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use RuntimeException;

/**
 * Meeting summary with Claude through the official Anthropic PHP SDK (anthropic-ai/sdk). Key and model from
 * config('meetings.ai'). The transcript is the meeting's live captions; nothing else of the meeting is sent.
 */
class ClaudeMeetingSummarizer implements MeetingSummarizer
{
    public function summarize(string $title, string $transcript): string
    {
        $key = (string) config('meetings.ai.key');
        if ($key === '') {
            throw new RuntimeException('No AI key is set (MEET_AI_KEY).');
        }
        $client = new Client(apiKey: $key);
        try {
            $message = $client->messages->create(
                model: (string) config('meetings.ai.model', 'claude-opus-5-5'),
                maxTokens: 16000,
                outputConfig: ['effort' => 'medium'],
                system: 'You write meeting notes for a small business. Answer in the main language of the transcript. Use short plain sentences and these parts: '
                    . 'Summary (3 to 5 lines), Decisions, Action items (who - what - by when, if said), Open questions. Leave a part out if the transcript has nothing for it. '
                    . 'Only use what is in the transcript; do not invent names, dates or numbers.',
                messages: [['role' => 'user', 'content' => "Meeting: {$title}\n\nTranscript (live captions, may have small recognition mistakes):\n{$transcript}"]],
            );
        } catch (APIStatusException $e) {
            throw new RuntimeException('The AI service answered with an error (' . ($e->type?->value ?? 'unknown') . ').', 0, $e);
        } catch (APIConnectionException $e) {
            throw new RuntimeException('The AI service could not be reached.', 0, $e);
        }
        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The AI service declined to summarise this meeting.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        if (trim($text) === '') {
            throw new RuntimeException('The AI service gave an empty summary.');
        }

        return trim($text);
    }
}
