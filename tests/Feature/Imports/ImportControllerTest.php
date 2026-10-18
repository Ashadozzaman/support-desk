<?php

declare(strict_types=1);

use App\Enums\Role as RoleEnum;
use App\Jobs\ProcessTicketImportJob;
use App\Models\Import;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
});

function controllerAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    return $admin;
}

test('an admin can upload a csv and the import job is dispatched after commit', function () {
    Queue::fake();

    $admin = controllerAdmin();
    $file = UploadedFile::fake()->create('tickets.csv', 10, 'text/csv');

    $response = $this->actingAs($admin)->post(route('admin.imports.store'), ['file' => $file]);

    $import = Import::firstOrFail();
    $response->assertRedirect(route('admin.imports.show', $import));

    Queue::assertPushed(ProcessTicketImportJob::class, fn (ProcessTicketImportJob $job) => $job->importId === $import->id);
});

test('an admin can view the imports index and create pages', function () {
    $admin = controllerAdmin();
    Import::factory()->create(['user_id' => $admin->id]);

    $this->actingAs($admin)->get(route('admin.imports.index'))->assertOk();
    $this->actingAs($admin)->get(route('admin.imports.create'))->assertOk();
});

test('an agent cannot access the imports admin pages', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    $this->actingAs($agent)->get(route('admin.imports.index'))->assertForbidden();
});

test('a customer cannot access the imports admin pages', function () {
    $customer = User::factory()->create();
    $customer->assignRole(RoleEnum::Customer->value);

    $this->actingAs($customer)->get(route('admin.imports.index'))->assertForbidden();
});

test('an admin can download the sample csv template', function () {
    $admin = controllerAdmin();

    $response = $this->actingAs($admin)->get(route('admin.imports.sample'));

    $response->assertOk();
    $response->assertHeader('content-disposition', 'attachment; filename=tickets-import-sample.csv');
});

test('an agent cannot download the sample csv template', function () {
    $agent = User::factory()->create();
    $agent->assignRole(RoleEnum::Agent->value);

    $this->actingAs($agent)->get(route('admin.imports.sample'))->assertForbidden();
});

test('the error report download is a csv listing each failed row', function () {
    $admin = controllerAdmin();
    $import = Import::factory()->create(['user_id' => $admin->id, 'failed_rows' => 1]);
    $import->importErrors()->create([
        'row_number' => 5,
        'errors' => ['Something went wrong.'],
        'raw_row' => ['subject' => 'x'],
    ]);

    $response = $this->actingAs($admin)->get(route('admin.imports.errors', $import));

    $response->assertOk();
    expect($response->streamedContent())->toContain('Something went wrong.');
});
