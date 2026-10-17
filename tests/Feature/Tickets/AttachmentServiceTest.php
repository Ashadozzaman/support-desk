<?php

declare(strict_types=1);

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a failed attachment insert deletes the file it had already stored', function () {
    Storage::fake('local');

    $ticket = Ticket::factory()->create();
    $uploader = User::factory()->create();
    $file = UploadedFile::fake()->create('note.pdf', 10, 'application/pdf');

    $this->mock(AttachmentRepositoryInterface::class, function ($mock) {
        $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('db failure'));
    });

    $service = app(AttachmentService::class);

    expect(fn () => $service->store($ticket, null, $uploader, $file))
        ->toThrow(RuntimeException::class, 'db failure');

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('a file with a disallowed extension is rejected before it is stored', function () {
    Storage::fake('local');

    $ticket = Ticket::factory()->create();
    $uploader = User::factory()->create();
    $file = UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload');

    $service = app(AttachmentService::class);

    expect(fn () => $service->store($ticket, null, $uploader, $file))
        ->toThrow(ValidationException::class);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});
