<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentController extends Controller
{
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
