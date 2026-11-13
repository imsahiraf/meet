<?php

namespace Modules\Groups\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PollClosedException extends Exception
{
    public function __construct(string $message = 'This poll is closed and no longer accepting votes.')
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
