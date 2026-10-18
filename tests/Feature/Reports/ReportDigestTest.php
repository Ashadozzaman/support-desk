<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Notifications\WeeklyReportDigest;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * "Today" is Monday 2026-01-12 (Dhaka): this week = Jan 5–11, previous = Dec 29–Jan 4.
 */
beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-01-12 08:00', 'Asia/Dhaka'));
    Notification::fake();
});

function digestUser(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user;
}

function digestRollup(string $date, array $values): void
{
    DB::table('ticket_daily_stats')->insert([
        'stat_date' => $date,
        'category_id' => 0,
        'priority' => 'medium',
        'agent_id' => 0,
        ...$values,
    ]);
}

test('the digest compares the last 7 days with the 7 before and goes only to report viewers', function () {
    $admin = digestUser(RoleEnum::Admin);
    $agent = digestUser(RoleEnum::Agent);
    digestRollup('2026-01-06', ['created_count' => 10, 'resolved_count' => 8, 'sla_met_count' => 6, 'sla_breached_count' => 2]);
    digestRollup('2026-01-02', ['created_count' => 4, 'resolved_count' => 5, 'sla_met_count' => 5]);
    digestRollup('2026-01-12', ['created_count' => 99]); // today — not in either week

    $this->artisan('reports:weekly-digest')->expectsOutputToContain('Digest queued for 1 recipient')->assertSuccessful();

    Notification::assertNotSentTo($agent, WeeklyReportDigest::class);
    Notification::assertSentTo($admin, WeeklyReportDigest::class, function (WeeklyReportDigest $n) use ($admin) {
        $kpis = collect($n->digest['kpis'])->keyBy('label');
        $mail = $n->toMail($admin);
        $csv = $mail->rawAttachments[0]['data'];

        return $n->digest['period'] === ['from' => '2026-01-05', 'to' => '2026-01-11']
            && $kpis['Tickets created'] === ['label' => 'Tickets created', 'current' => 10, 'previous' => 4]
            && $kpis['SLA compliance %']['current'] === 75.0
            && $kpis['SLA compliance %']['previous'] === 100.0
            && $mail->rawAttachments[0]['name'] === 'weekly-report-2026-01-05_2026-01-11.csv'
            && str_contains($csv, "\"Tickets created\",10,4\r\n");
    });
});

test('the digest is sent at most once per week unless forced', function () {
    digestUser(RoleEnum::Admin);

    $this->artisan('reports:weekly-digest')->assertSuccessful();
    $this->artisan('reports:weekly-digest')->expectsOutputToContain('Nothing sent')->assertSuccessful();
    Notification::assertSentTimes(WeeklyReportDigest::class, 1);

    $this->artisan('reports:weekly-digest --force')->assertSuccessful();
    Notification::assertSentTimes(WeeklyReportDigest::class, 2);
});
