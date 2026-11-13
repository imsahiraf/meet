<?php

namespace Modules\Contacts\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DuplicateContactRequestException extends Exception
{
    public function __construct(string $message = 'A pending contact request already exists between these users.')
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
