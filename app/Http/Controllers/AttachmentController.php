<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Download an attachment.
     *
     * Served by the same controller for the web app and the API; internal-note
     * attachments follow the note's visibility (AttachmentPolicy).
     *
     * @tags Replies
     */
    #[Response(200, 'The file, as an attachment.', mediaType: 'application/octet-stream', type: 'string', format: 'binary')]
    public function download(Attachment $attachment): StreamedResponse
    {
        $this->authorize('download', $attachment);

        return Storage::disk('local')->download(
            $attachment->disk_path,
            $attachment->original_name,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }
}
