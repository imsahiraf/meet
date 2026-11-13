<?php

namespace Modules\Whiteboard\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhiteboardObjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'tool' => $this->tool,
            'data' => $this->data,
            'color' => $this->color,
            'thickness' => $this->thickness,
            'z_index' => $this->z_index,
            'created_by' => $this->created_by,
            'created_by_participant_id' => $this->created_by_participant_id,
            'creator_name' => $this->creatorName(),
        ];
    }
}
