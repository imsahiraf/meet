<?php

namespace Modules\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Modules\Chat\Models\Message;
use Modules\Media\Exceptions\OneViewAlreadyConsumedException;
use Modules\Media\Http\Requests\UploadMediaRequest;
use Modules\Media\Http\Resources\MediaResource;
use Modules\Media\Models\Media;
use Modules\Media\Services\MediaService;
use Modules\Media\Services\OneViewService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly OneViewService $oneView,
    ) {
    }

    /**
     * RAS Media module - chunked/resumable in the spec; see
     * Media/config/config.php for why this is single-request instead.
     */
    public function store(UploadMediaRequest $request): JsonResponse
    {
        $media = $this->media->store(
            $request->user(),
            $request->file('file'),
            (bool) $request->boolean('is_voice_note'),
            $request->input('duration_seconds'),
        );

        return response()->json([
            'success' => true,
            'message' => 'File uploaded.',
            'data' => new MediaResource($media),
            'errors' => null,
        ], 201);
    }

    public function show(Media $media): StreamedResponse|JsonResponse
    {
        $user = Auth::user();

        if (! $this->media->canAccess($user, $media)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this file.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        $oneViewMessage = Message::where('media_id', $media->id)->where('message_type', 'one_view')->first();

        if ($oneViewMessage) {
            try {
                $result = $this->oneView->view($user, $oneViewMessage);
            } catch (OneViewAlreadyConsumedException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'errors' => null,
                ], 410);
            }

            // A Super Access viewer force-downloads the file (FR One
            // View Super Access) instead of an inline preview; either
            // way the server copy is deleted right after this response
            // finishes streaming - see streamOneViewMedia() below for
            // why that can't just be Storage::delete() called inline
            // here (the file still needs to exist while the response
            // body is actually being sent, which happens after this
            // method returns).
            $disposition = $user->one_view_super_access ? 'attachment' : 'inline';

            return $this->streamOneViewMedia($media, $disposition, $result['consumed']);
        }

        // Regular attachments are re-requested via a fresh <img src>
        // every time a chat thread is opened (ordinary browser
        // behavior, not abuse) - browser-side caching avoids
        // re-downloading the same file on every page load.
        return Storage::disk($media->disk)->response($media->path, $media->original_filename, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Streams a One View file exactly like Storage::disk()->response()
     * does, but deletes the underlying file once the stream callback
     * finishes sending it - Storage's own response()/download() return
     * a plain StreamedResponse (not a BinaryFileResponse), so there's
     * no built-in deleteFileAfterSend() to reach for here. The delete
     * only happens when $shouldDelete is true (a receiver's genuine
     * first, consuming view) - not for the sender's own unlimited
     * preview, and not on an already-consumed 410 (caller never reaches
     * this method in that case).
     */
    private function streamOneViewMedia(Media $media, string $disposition, bool $shouldDelete): StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        $response = new StreamedResponse;
        $mimeType = $media->is_encrypted_at_rest ? $media->mime_type : $disk->mimeType($media->path);

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition($disposition, $media->original_filename));

        if ($media->is_encrypted_at_rest) {
            // Encrypted-at-rest content has to be decrypted in full
            // before it can be sent - no partial/streamed decryption
            // with Crypt::decryptString() - so Content-Length is set
            // from the decrypted size instead of the on-disk size.
            $plaintext = Crypt::decryptString($disk->get($media->path));
            $response->headers->set('Content-Length', (string) strlen($plaintext));
            $response->setCallback(function () use ($disk, $media, $shouldDelete, $plaintext) {
                echo $plaintext;

                if ($shouldDelete) {
                    $disk->delete($media->path);
                }
            });

            return $response;
        }

        $response->headers->set('Content-Length', (string) $disk->size($media->path));
        $response->setCallback(function () use ($disk, $media, $shouldDelete) {
            $stream = $disk->readStream($media->path);
            fpassthru($stream);
            fclose($stream);

            if ($shouldDelete) {
                $disk->delete($media->path);
            }
        });

        return $response;
    }
}
