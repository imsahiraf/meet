<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ChatExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChatExportController extends Controller
{
    public function __construct(
        private readonly ChatExportService $export,
    ) {
    }

    public function __invoke(Request $request, Conversation $conversation): BinaryFileResponse
    {
        $this->authorize('view', $conversation);

        $zipPath = $this->export->export($request->user(), $conversation);

        return response()->download($zipPath, $conversation->uuid.'-export.zip')->deleteFileAfterSend(true);
    }
}
