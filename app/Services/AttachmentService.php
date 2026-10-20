<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
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

        return $this->persist(
            $ticket,
            $reply,
            $uploader,
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalName(),
            strtolower($file->getClientOriginalExtension()),
            $file->getMimeType() ?? 'application/octet-stream',
        );
    }

    /**
     * Same rules as an upload, for files that arrive as bytes (an email
     * attachment, docs/IMAP.md §5). The web form's `mimes:` rule checks the
     * type sniffed from the content, so this does too: both the name's
     * extension and the sniffed type must be on the allow-list — a renamed
     * .exe is refused.
     *
     * @throws ValidationException
     */
    public function storeContents(Ticket $ticket, ?TicketReply $reply, User $uploader, string $filename, string $contents): Attachment
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';

        $this->assertExtensionAllowed($extension);
        $this->assertSizeAllowed(strlen($contents));

        $sniffedExtensions = MimeTypes::getDefault()->getExtensions($mimeType);
        if (array_intersect($sniffedExtensions, config('support.uploads.mimes')) === []) {
            throw ValidationException::withMessages([
                'attachments' => "The content of {$filename} doesn't match an allowed file type.",
            ]);
        }

        return $this->persist($ticket, $reply, $uploader, $contents, $filename, $extension, $mimeType);
    }

    private function persist(
        Ticket $ticket,
        ?TicketReply $reply,
        User $uploader,
        string $contents,
        string $originalName,
        string $extension,
        string $mimeType,
    ): Attachment {
        $diskPath = sprintf('tickets/%d/%s.%s', $ticket->id, Str::uuid()->toString(), $extension);

        Storage::disk('local')->put($diskPath, $contents);

        try {
            return $this->attachments->create(
                $ticket,
                $reply,
                $uploader,
                $diskPath,
                mb_substr($originalName, 0, 255),
                $mimeType,
                strlen($contents),
                hash('sha256', $contents),
            );
        } catch (Throwable $e) {
            Storage::disk('local')->delete($diskPath);

            throw $e;
        }
    }

    private function assertAllowed(UploadedFile $file): void
    {
        $this->assertExtensionAllowed(strtolower($file->getClientOriginalExtension()));
        $this->assertSizeAllowed((int) $file->getSize());
    }

    private function assertExtensionAllowed(string $extension): void
    {
        if (! in_array($extension, config('support.uploads.mimes'), true)) {
            throw ValidationException::withMessages([
                'attachments' => "Files of type .{$extension} are not allowed.",
            ]);
        }
    }

    private function assertSizeAllowed(int $bytes): void
    {
        if ($bytes > config('support.uploads.max_kb') * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'One of the attached files exceeds the maximum allowed size.',
            ]);
        }
    }
}
