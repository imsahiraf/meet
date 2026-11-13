<?php

namespace Modules\Admin\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminRoleImmutableException extends Exception
{
    public function __construct(string $message = 'The Super Admin role cannot be deleted.')
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
