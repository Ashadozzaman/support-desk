<?php

declare(strict_types=1);

use App\Contracts\Repositories\TicketRepositoryInterface;
use App\Enums\ImportStatus;
use App\Enums\Role as RoleEnum;
use App\Jobs\ProcessTicketImportJob;
use App\Models\Category;
use App\Models\Import;
use App\Models\Ticket;
use App\Models\User;
use App\Repositories\Eloquent\TicketRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function importAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    return $admin;
}

function importCustomer(string $email): User
{
    $customer = User::factory()->create(['email' => $email]);
    $customer->assignRole(RoleEnum::Customer->value);

    return $customer;
}

function storeImportCsv(string $contents): Import
{
    $path = 'imports/'.Str::uuid().'.csv';
    Storage::disk('local')->put($path, $contents);

    return Import::factory()->create([
        'user_id' => importAdmin()->id,
        'disk_path' => $path,
    ]);
}

test('a valid csv file creates a ticket per row and completes the import', function () {
    $customer = importCustomer('alice@example.com');
    $category = Category::factory()->create(['slug' => 'billing']);

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."REF-1,Cannot log in,Body one,high,{$category->slug},{$customer->email}\n"
        ."REF-2,Billing question,Body two,low,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Completed);
    expect($import->total_rows)->toBe(2);
    expect($import->success_rows)->toBe(2);
    expect($import->failed_rows)->toBe(0);
    expect(Ticket::count())->toBe(2);
    expect(Ticket::where('external_ref', 'REF-1')->first()->category_id)->toBe($category->id);
});

test('invalid rows are reported in import_errors with their row number and create no ticket', function () {
    $customer = importCustomer('bob@example.com');

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."REF-1,Valid ticket,Body,medium,,{$customer->email}\n"
        .",,Body,medium,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->success_rows)->toBe(1);
    expect($import->failed_rows)->toBe(1);
    expect($import->status)->toBe(ImportStatus::CompletedWithErrors);

    $error = $import->importErrors()->first();
    expect($error->row_number)->toBe(3); // row 1 = header, REF-1 = row 2, this row = row 3
    expect($error->errors)->toContain('The subject field is required.');
    expect(Ticket::count())->toBe(1);
});

test('external_ref is optional — a row without one gets an auto-generated ref', function () {
    $customer = importCustomer('heidi@example.com');

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        .",No external ref supplied,Body,low,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->success_rows)->toBe(1);
    expect($import->failed_rows)->toBe(0);

    $ticket = Ticket::firstOrFail();
    expect($ticket->external_ref)->not->toBeNull();
    expect($ticket->external_ref)->not->toBe('');
});

test('re-uploading the same file with no external_ref column is still caught as a duplicate', function () {
    $customer = importCustomer('ivan@example.com');

    $csv = "subject,description,priority,category,customer_email\n"
        ."Cannot log in,Password reset is not working,high,,{$customer->email}\n";

    $first = storeImportCsv($csv);
    ProcessTicketImportJob::dispatchSync($first->id);

    $second = storeImportCsv($csv);
    ProcessTicketImportJob::dispatchSync($second->id);

    expect($first->refresh()->success_rows)->toBe(1);
    expect($second->refresh()->success_rows)->toBe(0);
    expect($second->failed_rows)->toBe(1);
    expect($second->importErrors()->first()->errors)->toContain(
        'A ticket with identical subject, description, priority, category and customer_email already exists.',
    );
    expect(Ticket::count())->toBe(1);
});

test('a row whose external_ref already exists in the database is rejected as a duplicate', function () {
    $customer = importCustomer('carol@example.com');
    Ticket::factory()->create(['external_ref' => 'DUP-1']);

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."DUP-1,Already imported,Body,low,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->failed_rows)->toBe(1);
    expect($import->success_rows)->toBe(0);
    expect($import->importErrors()->first()->errors)->toContain('A ticket with this external_ref already exists.');
});

test('a duplicate external_ref within the same file is imported only once', function () {
    $customer = importCustomer('dana@example.com');

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."SAME-1,First,Body,low,,{$customer->email}\n"
        ."SAME-1,Second,Body,low,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect(Ticket::where('external_ref', 'SAME-1')->count())->toBe(1);
    expect($import->success_rows)->toBe(1);
    expect($import->failed_rows)->toBe(1);
    expect($import->importErrors()->first()->errors)->toContain('Duplicate row within the file: another row has this external_ref.');
});

test('two identical rows with no external_ref in the same file report a content-based duplicate message', function () {
    $customer = importCustomer('judy@example.com');

    $csv = "subject,description,priority,category,customer_email\n"
        ."Cannot log in,Body,low,,{$customer->email}\n"
        ."Cannot log in,Body,low,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->success_rows)->toBe(1);
    expect($import->failed_rows)->toBe(1);
    expect($import->importErrors()->first()->errors)->toContain(
        'Duplicate row within the file: another row has identical subject, description, priority, category and customer_email.',
    );
});

test('an unresolvable customer email is reported as an error and creates no ticket', function () {
    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."REF-1,Subject,Body,low,,unknown@example.com\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->failed_rows)->toBe(1);
    expect($import->importErrors()->first()->errors)->toContain("Unknown customer email 'unknown@example.com'.");
    expect(Ticket::count())->toBe(0);
});

test('an unresolvable category slug is reported as an error', function () {
    $customer = importCustomer('gina@example.com');

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."REF-1,Subject,Body,low,not-a-real-category,{$customer->email}\n";

    $import = storeImportCsv($csv);

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect($import->failed_rows)->toBe(1);
    expect($import->importErrors()->first()->errors)->toContain("Unknown category 'not-a-real-category'.");
});

test('a chunk that fails is rolled back and reported without aborting the rest of the import', function () {
    config(['support.import.chunk_size' => 1]);

    $customer = importCustomer('erin@example.com');

    $csv = "external_ref,subject,description,priority,category,customer_email\n"
        ."REF-1,First,Body,low,,{$customer->email}\n"
        ."REF-2,Second,Body,low,,{$customer->email}\n"
        ."REF-3,Third,Body,low,,{$customer->email}\n";

    $import = storeImportCsv($csv);

    $realRepository = app(TicketRepository::class);
    $callCount = 0;

    $this->mock(TicketRepositoryInterface::class, function ($mock) use ($realRepository, &$callCount) {
        $mock->shouldReceive('create')->andReturnUsing(function (...$args) use ($realRepository, &$callCount) {
            $callCount++;

            if ($callCount === 2) {
                throw new RuntimeException('simulated chunk failure');
            }

            return $realRepository->create(...$args);
        });
    });

    ProcessTicketImportJob::dispatchSync($import->id);
    $import->refresh();

    expect(Ticket::count())->toBe(2); // REF-1 and REF-3 committed; REF-2's chunk rolled back
    expect($import->success_rows)->toBe(2);
    expect($import->failed_rows)->toBe(1);
    expect(Ticket::where('external_ref', 'REF-2')->exists())->toBeFalse();
});

test('the failed handler marks the import as failed', function () {
    $import = Import::factory()->create(['status' => ImportStatus::Processing]);

    (new ProcessTicketImportJob($import->id))->failed(new RuntimeException('boom'));

    expect($import->refresh()->status)->toBe(ImportStatus::Failed);
});

test('a large file is processed without the whole file being held in memory', function () {
    $customer = importCustomer('frank@example.com');

    $rowCount = 20_000;
    $handle = fopen('php://temp', 'w+');
    fputcsv($handle, ['external_ref', 'subject', 'description', 'priority', 'category', 'customer_email']);

    for ($i = 1; $i <= $rowCount; $i++) {
        fputcsv($handle, ["BULK-{$i}", "Subject {$i}", 'Body text', 'low', '', $customer->email]);
    }

    rewind($handle);
    $contents = stream_get_contents($handle);
    fclose($handle);

    $path = 'imports/'.Str::uuid().'.csv';
    Storage::disk('local')->put($path, $contents);
    unset($contents);
    gc_collect_cycles();

    $import = Import::factory()->create(['user_id' => importAdmin()->id, 'disk_path' => $path]);

    $before = memory_get_usage();
    ProcessTicketImportJob::dispatchSync($import->id);
    $peak = memory_get_peak_usage();

    $import->refresh();

    expect($import->success_rows)->toBe($rowCount);
    expect(Ticket::count())->toBe($rowCount);
    // A naive "load everything into one array" implementation would grow
    // roughly linearly with row count; streamed chunking should not.
    expect($peak - $before)->toBeLessThan(100 * 1024 * 1024);
})->group('slow');
