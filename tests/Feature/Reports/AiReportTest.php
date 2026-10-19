<?php

declare(strict_types=1);

use App\Enums\AiInteractionStatus;
use App\Enums\ChatSessionStatus;
use App\Enums\Role as RoleEnum;
use App\Models\AiInteraction;
use App\Models\ChatSession;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['support.sla.timezone' => 'Asia/Dhaka']);
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(RoleEnum::Admin->value);
});

test('AI tab: conversations, deflection rate and provider usage in the period', function () {
    ChatSession::factory()->count(3)->create(['status' => ChatSessionStatus::Closed]);
    ChatSession::factory()->create(['status' => ChatSessionStatus::HandedOff]);
    ChatSession::factory()->create(['status' => ChatSessionStatus::Active]);
    // Outside the default period (the last 30 days).
    $old = ChatSession::factory()->create(['status' => ChatSessionStatus::HandedOff]);
    ChatSession::query()->whereKey($old->id)->update(['created_at' => now()->subDays(45)]);

    AiInteraction::factory()->create(['tokens_in' => 100, 'tokens_out' => 50, 'latency_ms' => 1000]);
    AiInteraction::factory()->create(['tokens_in' => 200, 'tokens_out' => 50, 'latency_ms' => 3000]);
    AiInteraction::factory()->create(['status' => AiInteractionStatus::Failed, 'tokens_in' => 0, 'tokens_out' => 0, 'latency_ms' => 15000]);

    $this->actingAs($this->admin)
        ->get(route('reports.index', ['tab' => 'ai']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'ai')
            ->where('report.chats', [
                'total' => 5,
                'handed_off' => 1,
                'closed' => 3,
                'active' => 1,
                'deflection_pct' => 60,
            ])
            ->where('report.calls.total', 3)
            ->where('report.calls.failed', 1)
            ->where('report.calls.tokens', 400)
            // Only successful calls count towards the answer time.
            ->where('report.calls.avg_latency_ms', 2000));
});

test('AI tab with no conversations shows no deflection rate', function () {
    $this->actingAs($this->admin)
        ->get(route('reports.index', ['tab' => 'ai']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.chats.deflection_pct', null)
            ->where('report.calls.avg_latency_ms', null));
});

test('agents cannot open the AI tab', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    $this->actingAs($agent)->get(route('reports.index', ['tab' => 'ai']))->assertForbidden();
});

test('the AI tab exports as a summary CSV', function () {
    ChatSession::factory()->create(['status' => ChatSessionStatus::Closed]);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.export.summary', ['tab' => 'ai']))
        ->assertOk();

    $lines = explode("\r\n", trim($response->streamedContent()));
    expect($lines[0])->toBe('Metric,Value')
        ->and($lines)->toContain('Conversations,1')
        ->and($lines)->toContain('"Deflection %",100');
});
