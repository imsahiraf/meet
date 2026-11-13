<?php

namespace Modules\Whiteboard\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhiteboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'meeting_id' => $this->meeting_id,
            'title' => $this->title,
            'permission_mode' => $this->permission_mode,
            'status' => $this->status,
            'pages' => $this->whenLoaded('pages', fn () => $this->pages->map(fn ($page) => [
                'id' => $page->id,
                'page_number' => $page->page_number,
                'name' => $page->name,
            ])),
        ];
    }
}
