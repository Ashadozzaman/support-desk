<?php

declare(strict_types=1);

use App\Enums\ReportExportStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketStatus;
use App\Jobs\GenerateReportExportJob;
use App\Models\Category;
use App\Models\ReportExport;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ReportExportReady;
use App\Services\ReportExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function exportUser(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

/**
 * Tickets created inside the default 30-day window.
 */
function exportTickets(int $count, array $attributes = []): void
{
    Ticket::factory()->count($count)->create($attributes);
}

test('only reports.export holders can export', function (RoleEnum $role) {
    $user = exportUser($role);

    $this->actingAs($user)->get(route('reports.export.summary'))->assertForbidden();
    $this->actingAs($user)->get(route('reports.export.tickets'))->assertForbidden();
})->with([RoleEnum::Agent, RoleEnum::Customer]);

test('the summary export streams the current tab as CSV', function () {
    DB::table('ticket_daily_stats')->insert([
        'stat_date' => now('Asia/Dhaka')->toDateString(),
        'category_id' => 0,
        'priority' => 'high',
        'agent_id' => 0,
        'resolved_count' => 4,
        'sla_met_count' => 3,
        'sla_breached_count' => 1,
    ]);

    $response = $this->actingAs(exportUser(RoleEnum::Admin))
        ->get(route('reports.export.summary', ['tab' => 'sla']))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertDownload();

    $lines = explode("\r\n", trim($response->streamedContent()));
    expect($lines[0])->toBe('Priority,"Within SLA",Late,"Compliance %"')
        ->and($lines)->toContain('high,3,1,75')
        ->and(end($lines))->toBe('All,3,1,75');
});

test('a small ticket export streams immediately with formula cells neutralised', function () {
    $category = Category::factory()->create(['name' => '=HYPERLINK("http://evil")']);
    exportTickets(3, ['category_id' => $category->id, 'status' => TicketStatus::Open->value]);
    Queue::fake();

    $response = $this->actingAs(exportUser(RoleEnum::Admin))
        ->get(route('reports.export.tickets'))
        ->assertOk()
        ->assertDownload();

    $lines = explode("\r\n", trim($response->streamedContent()));

    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toStartWith('Ticket,Created,Status,Priority,Category,Agent')
        ->and($lines[1])->toContain('"\'=HYPERLINK(""http://evil"")"');
    Queue::assertNothingPushed();
});

test('a ticket export over the sync limit is queued after commit instead of streamed', function () {
    config(['support.reports.export_sync_limit' => 2]);
    exportTickets(3);
    Queue::fake();
    $admin = exportUser(RoleEnum::Admin);

    $this->actingAs($admin)
        ->from(route('reports.index'))
        ->get(route('reports.export.tickets'))
        ->assertRedirect(route('reports.index'));

    $export = ReportExport::query()->sole();
    expect($export->user_id)->toBe($admin->id)
        ->and($export->status)->toBe(ReportExportStatus::Pending)
        ->and($export->filters['to'])->toBe(now('Asia/Dhaka')->toDateString());

    Queue::assertPushedOn('exports', GenerateReportExportJob::class, fn ($job) => $job->exportId === $export->id);
});

test('the export job writes the file, completes and notifies the owner with a signed link', function () {
    exportTickets(5);
    Notification::fake();
    $export = ReportExport::factory()->create(['user_id' => exportUser(RoleEnum::Admin)->id]);

    (new GenerateReportExportJob($export->id))->handle(app(ReportExportService::class));

    $export->refresh();
    expect($export->status)->toBe(ReportExportStatus::Completed)
        ->and($export->row_count)->toBe(5)
        ->and(Storage::disk('local')->exists($export->disk_path))->toBeTrue()
        ->and(substr_count(Storage::disk('local')->get($export->disk_path), "\r\n"))->toBe(6);

    Notification::assertSentTo($export->user, ReportExportReady::class, function (ReportExportReady $n) use ($export) {
        return str_contains($n->url, "/reports/exports/{$export->id}/download")
            && str_contains($n->url, 'signature=')
            && $n->toArray($export->user)['download'] === true;
    });

    // Running it again (duplicate delivery) changes nothing and notifies no one.
    Notification::fake();
    (new GenerateReportExportJob($export->id))->handle(app(ReportExportService::class));
    Notification::assertNothingSent();
});

test('a failed export job marks the export failed', function () {
    $export = ReportExport::factory()->create();

    (new GenerateReportExportJob($export->id))->failed(new RuntimeException('disk full'));

    expect($export->refresh()->status)->toBe(ReportExportStatus::Failed);
});

test('the download link works only signed, unexpired, and for the owner', function () {
    $owner = exportUser(RoleEnum::Admin);
    Storage::disk('local')->put('exports/1.csv', "Ticket\r\n");
    $export = ReportExport::factory()->create([
        'user_id' => $owner->id,
        'status' => ReportExportStatus::Completed->value,
        'disk_path' => 'exports/1.csv',
    ]);
    $url = app(ReportExportService::class)->downloadUrl($export);

    $this->actingAs($owner)->get($url)->assertOk()->assertDownload("tickets-export-{$export->id}.csv");

    // Unsigned, tampered, or another admin holding the link.
    $this->actingAs($owner)->get(route('reports.exports.download', $export))->assertForbidden();
    $this->actingAs($owner)->get($url.'x')->assertForbidden();
    $this->actingAs(exportUser(RoleEnum::Admin))->get($url)->assertForbidden();

    // Expired.
    $this->travel(8)->days();
    $this->actingAs($owner)->get($url)->assertForbidden();
});

test('model:prune deletes expired exports together with their files', function () {
    Storage::disk('local')->put('exports/old.csv', 'x');
    Storage::disk('local')->put('exports/new.csv', 'x');
    ReportExport::factory()->create(['disk_path' => 'exports/old.csv', 'expires_at' => now()->subMinute()]);
    $kept = ReportExport::factory()->create(['disk_path' => 'exports/new.csv', 'expires_at' => now()->addDay()]);

    $this->artisan('model:prune', ['--model' => [ReportExport::class]])->assertSuccessful();

    expect(ReportExport::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(Storage::disk('local')->exists('exports/old.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('exports/new.csv'))->toBeTrue();
});

test('the signed url expires with the file', function () {
    $export = ReportExport::factory()->create(['expires_at' => now()->addDays(7)]);

    $url = app(ReportExportService::class)->downloadUrl($export);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe($export->expires_at->getTimestamp())
        ->and(URL::hasValidSignature(request()->create($url)))->toBeTrue();
});
