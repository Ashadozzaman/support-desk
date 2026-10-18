<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Repositories\ImportRepositoryInterface;
use App\Contracts\Repositories\TicketRepositoryInterface;
use App\DTOs\CreateTicketData;
use App\Enums\ActivityEvent;
use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Enums\TicketPriority;
use App\Events\ImportProgressUpdated;
use App\Models\Category;
use App\Models\Import;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SlaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use League\Csv\Reader;
use Throwable;

/**
 * Streams the uploaded file with league/csv (never loads it whole,
 * ARCHITECTURE.md §13) and processes it in fixed-size chunks so memory use
 * stays constant regardless of file size. tries=1 at the job level because
 * chunks are self-contained: a failure in one chunk is caught, rolled back,
 * and recorded as errors for that chunk only — the job keeps going rather
 * than re-running already-committed chunks on a job-level retry.
 */
class ProcessTicketImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $importId)
    {
        $this->queue = 'imports';
    }

    public function handle(
        ImportRepositoryInterface $imports,
        TicketRepositoryInterface $tickets,
        SlaService $sla,
        ActivityLogService $activity,
    ): void {
        $import = $imports->findOrFail($this->importId);
        $imports->markStatus($import, ImportStatus::Processing);

        $path = Storage::disk('local')->path($import->disk_path);
        $chunkSize = max(1, (int) config('support.import.chunk_size'));

        $imports->setTotalRows($import, $this->countDataRows($path));

        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);

        $seenRefs = [];
        $buffer = [];
        $rowNumber = 1; // row 1 is the header

        foreach ($reader->getRecords() as $record) {
            $rowNumber++;
            $buffer[] = ['row_number' => $rowNumber, 'raw' => $record];

            if (count($buffer) >= $chunkSize) {
                $this->processChunk($buffer, $seenRefs, $import, $imports, $tickets, $sla, $activity);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->processChunk($buffer, $seenRefs, $import, $imports, $tickets, $sla, $activity);
        }

        $final = $imports->findOrFail($import->id);
        $imports->markStatus(
            $final,
            $final->failed_rows > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
        );

        ImportProgressUpdated::dispatch($imports->findOrFail($import->id));
    }

    /**
     * One pass over the file counting data rows only (streamed, so memory
     * stays flat) — gives the UI a real denominator before processing starts.
     */
    private function countDataRows(string $path): int
    {
        $reader = Reader::createFromPath($path, 'r');
        $reader->setHeaderOffset(0);

        return iterator_count($reader->getRecords());
    }

    /**
     * @param  list<array{row_number: int, raw: array<string, mixed>}>  $rows
     * @param  array<string, true>  $seenRefs
     */
    private function processChunk(
        array $rows,
        array &$seenRefs,
        Import $import,
        ImportRepositoryInterface $imports,
        TicketRepositoryInterface $tickets,
        SlaService $sla,
        ActivityLogService $activity,
    ): void {
        [$validRows, $errorRows] = $this->validateRows($rows, $seenRefs);

        try {
            DB::transaction(function () use ($validRows, $import, $tickets, $sla, $activity) {
                foreach ($validRows as $row) {
                    $data = new CreateTicketData(
                        subject: $row['subject'],
                        description: $row['description'],
                        priority: $row['priority'],
                        categoryId: $row['category_id'],
                        createdBy: $row['customer_id'],
                        externalRef: $row['external_ref'],
                    );

                    $window = $sla->calculate($data->priority, now());
                    $ticket = $tickets->create($data, $window->dueAt, $window->warningAt);

                    $activity->log(
                        $ticket,
                        ActivityEvent::TicketImported,
                        $import->user,
                        [],
                        ['external_ref' => $ticket->external_ref],
                        ['import_id' => $import->id, 'row_number' => $row['row_number']],
                    );
                }
            }, attempts: 3);

            $successCount = count($validRows);
        } catch (Throwable $e) {
            Log::error('Ticket import chunk failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);

            foreach ($validRows as $row) {
                $errorRows[] = [
                    'row_number' => $row['row_number'],
                    'errors' => ['Batch failed: '.$e->getMessage()],
                    'raw_row' => $row['raw'],
                ];
            }

            $successCount = 0;
        }

        $imports->recordErrors($import, $errorRows);
        $imports->incrementCounters($import, count($rows), $successCount, count($errorRows));

        DB::afterCommit(fn () => ImportProgressUpdated::dispatch($imports->findOrFail($import->id)));
    }

    /**
     * @param  list<array{row_number: int, raw: array<string, mixed>}>  $rows
     * @param  array<string, true>  $seenRefs
     * @return array{0: list<array<string, mixed>>, 1: list<array{row_number: int, errors: array<int, string>, raw_row: array<string, mixed>}>}
     */
    private function validateRows(array $rows, array &$seenRefs): array
    {
        // A row without its own external_ref still needs a stable identity
        // to dedupe against (otherwise re-uploading the same file would
        // silently create duplicate tickets every time) — a fingerprint of
        // its content stands in for a missing source-system id.
        $effectiveRefs = [];
        $hasExplicitRef = [];
        foreach ($rows as $i => $row) {
            $explicit = trim((string) ($row['raw']['external_ref'] ?? ''));
            $effectiveRefs[$i] = $explicit !== '' ? $explicit : $this->contentFingerprint($row['raw']);
            $hasExplicitRef[$i] = $explicit !== '';
        }

        $refs = array_values(array_unique($effectiveRefs));
        $existingRefs = $refs === [] ? [] : Ticket::query()->whereIn('external_ref', $refs)->pluck('external_ref')->all();

        $slugs = array_values(array_unique(array_filter(array_map(
            fn (array $row) => trim((string) ($row['raw']['category'] ?? '')),
            $rows,
        ))));
        $categoriesBySlug = Category::query()->whereIn('slug', $slugs)->get()->keyBy('slug');

        $emails = array_values(array_unique(array_filter(array_map(
            fn (array $row) => trim((string) ($row['raw']['customer_email'] ?? '')),
            $rows,
        ))));
        $customersByEmail = User::query()
            ->whereIn('email', $emails)
            ->with('roles')
            ->get()
            ->filter(fn (User $user) => $user->roles->contains('name', Role::Customer->value))
            ->keyBy('email');

        $validRows = [];
        $errorRows = [];

        foreach ($rows as $i => $row) {
            $record = $row['raw'];
            $errors = $this->runValidator($record)->errors()->all();

            $externalRef = $effectiveRefs[$i];
            $duplicateReason = $hasExplicitRef[$i]
                ? 'this external_ref'
                : 'identical subject, description, priority, category and customer_email';
            if (isset($seenRefs[$externalRef])) {
                $errors[] = "Duplicate row within the file: another row has {$duplicateReason}.";
            } elseif (in_array($externalRef, $existingRefs, true)) {
                $errors[] = "A ticket with {$duplicateReason} already exists.";
            }

            $categorySlug = trim((string) ($record['category'] ?? ''));
            $categoryId = null;
            if ($categorySlug !== '') {
                $category = $categoriesBySlug->get($categorySlug);
                if ($category === null) {
                    $errors[] = "Unknown category '{$categorySlug}'.";
                } else {
                    $categoryId = $category->id;
                }
            }

            $email = trim((string) ($record['customer_email'] ?? ''));
            $customer = $email !== '' ? $customersByEmail->get($email) : null;
            if ($email !== '' && $customer === null) {
                $errors[] = "Unknown customer email '{$email}'.";
            }

            if ($errors !== []) {
                $errorRows[] = ['row_number' => $row['row_number'], 'errors' => $errors, 'raw_row' => $record];

                continue;
            }

            $seenRefs[$externalRef] = true;

            $validRows[] = [
                'row_number' => $row['row_number'],
                'raw' => $record,
                'external_ref' => $externalRef,
                'subject' => (string) $record['subject'],
                'description' => (string) $record['description'],
                'priority' => TicketPriority::from($record['priority']),
                'category_id' => $categoryId,
                'customer_id' => $customer->id,
            ];
        }

        return [$validRows, $errorRows];
    }

    /**
     * Deterministic stand-in for a missing source-system id: the same
     * subject/description/priority/category/customer_email always hashes to
     * the same value, so re-uploading the same file is still caught as a
     * duplicate. Two distinct tickets that happen to share every one of
     * these fields would also collide — an accepted trade-off given there
     * is no real external id to key off of.
     *
     * @param  array<string, mixed>  $record
     */
    private function contentFingerprint(array $record): string
    {
        $parts = [
            trim((string) ($record['subject'] ?? '')),
            trim((string) ($record['description'] ?? '')),
            trim((string) ($record['priority'] ?? '')),
            trim((string) ($record['category'] ?? '')),
            trim((string) ($record['customer_email'] ?? '')),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function runValidator(array $record): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($record, [
            'external_ref' => ['nullable', 'string', 'max:64'],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'customer_email' => ['required', 'email'],
            'category' => ['nullable', 'string'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Ticket import job failed', ['import_id' => $this->importId, 'error' => $exception->getMessage()]);

        $import = Import::find($this->importId);

        if ($import !== null) {
            $import->update(['status' => ImportStatus::Failed]);
        }
    }
}
