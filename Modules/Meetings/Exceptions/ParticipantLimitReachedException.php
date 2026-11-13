<?php

namespace Modules\Meetings\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// FR-137/FR-140 admin-configurable "Maximum Participants".
class ParticipantLimitReachedException extends Exception
{
    public function __construct(string $message = 'This meeting has reached its maximum number of participants.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            'errors' => null,
        ], 409);
    }
}
