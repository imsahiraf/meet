<?php

namespace Modules\Groups\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupPollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userId = $request->user()?->id;

        return [
            'uuid' => $this->uuid,
            'question' => $this->question,
            'allow_multiple_votes' => $this->allow_multiple_votes,
            'closes_at' => $this->closes_at,
            'is_closed' => $this->isClosed(),
            'options' => $this->options->map(fn ($option) => [
                'id' => $option->id,
                'option_text' => $option->option_text,
                'vote_count' => $option->votes->count(),
                'voted_by_me' => $userId && $option->votes->contains('user_id', $userId),
            ]),
            'total_votes' => $this->options->sum(fn ($option) => $option->votes->count()),
            'created_at' => $this->created_at,
        ];
    }
}
