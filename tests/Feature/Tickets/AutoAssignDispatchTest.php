<?php

declare(strict_types=1);

use App\DTOs\CreateTicketData;
use App\Enums\Role as RoleEnum;
use App\Enums\TicketPriority;
use App\Jobs\AutoAssignTicketJob;
use App\Models\Category;
use App\Models\User;
use App\Services\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('creating a ticket dispatches AutoAssignTicketJob for that ticket', function () {
    Queue::fake();

    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);
    $category = Category::factory()->create();

    $ticket = app(TicketService::class)->create(
        new CreateTicketData(
            subject: 'Subject',
            description: 'Description',
            priority: TicketPriority::High,
            categoryId: $category->id,
            createdBy: $customer->id,
        ),
        $customer,
    );

    Queue::assertPushed(AutoAssignTicketJob::class, fn (AutoAssignTicketJob $job) => $job->ticketId === $ticket->id);
});

test('a job dispatched after commit inside a rolled-back transaction is never pushed', function () {
    // Queue::fake() bypasses the afterCommit deferral entirely (it records
    // the push immediately regardless of transaction state), so this uses
    // the real `database` queue connection and inspects the jobs table —
    // the only way to observe the deferral ARCHITECTURE.md §10 describes.
    config(['queue.default' => 'database']);

    try {
        DB::transaction(function () {
            AutoAssignTicketJob::dispatch(999_999)->afterCommit();

            throw new RuntimeException('force a rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('jobs')->count())->toBe(0);

    DB::transaction(function () {
        AutoAssignTicketJob::dispatch(999_998)->afterCommit();
    });

    expect(DB::table('jobs')->count())->toBe(1);
});
