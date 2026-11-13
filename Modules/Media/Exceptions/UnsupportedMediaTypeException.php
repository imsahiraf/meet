<?php

namespace Modules\Media\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnsupportedMediaTypeException extends Exception
{
    public function __construct(string $message = 'This file type is not supported.')
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
        ], 422);
    }
}
