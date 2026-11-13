<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Chat\Services\LinkPreviewService;

class LinkPreviewController extends Controller
{
    public function __construct(
        private readonly LinkPreviewService $previews,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048']]);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $this->previews->preview($data['url']),
            'errors' => null,
        ]);
    }
}
