<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AttachmentService
{
    public function __construct(private readonly AttachmentRepositoryInterface $attachments) {}

    /**
     * Stores the file on the private disk, then inserts the DB row. If the
     * insert fails, the stored file is deleted (ARCHITECTURE.md §11
     * compensating action) so a failed write never leaves an orphaned file.
     */
    public function store(Ticket $ticket, ?TicketReply $reply, User $uploader, UploadedFile $file): Attachment
    {
        $this->assertAllowed($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $diskPath = sprintf('tickets/%d/%s.%s', $ticket->id, Str::uuid()->toString(), $extension);
        $checksum = hash_file('sha256', $file->getRealPath());

        Storage::disk('local')->put($diskPath, (string) file_get_contents($file->getRealPath()));

        try {
            return $this->attachments->create(
                $ticket,
                $reply,
                $uploader,
                $diskPath,
                $file->getClientOriginalName(),
                $file->getMimeType() ?? 'application/octet-stream',
                $file->getSize() ?: 0,
                $checksum,
            );
        } catch (Throwable $e) {
            Storage::disk('local')->delete($diskPath);

            throw $e;
        }
    }

    private function assertAllowed(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedMimes = config('support.uploads.mimes');
        $maxKb = config('support.uploads.max_kb');

        if (! in_array($extension, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'attachments' => "Files of type .{$extension} are not allowed.",
            ]);
        }

        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'One of the attached files exceeds the maximum allowed size.',
            ]);
        }
    }
}
