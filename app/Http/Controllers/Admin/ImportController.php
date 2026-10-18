<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Services\TicketImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private readonly TicketImportService $imports) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage', Import::class);

        return Inertia::render('admin/imports/index', [
            'imports' => ImportResource::collection($this->imports->paginateFor($request->user())),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('manage', Import::class);

        return Inertia::render('admin/imports/create', [
            'maxFileSizeMb' => (int) (config('support.import.max_kb') / 1024),
        ]);
    }

    /**
     * A template file showing the expected columns, so an admin doesn't
     * have to guess the schema before their first upload.
     */
    public function sample(): BinaryFileResponse
    {
        $this->authorize('manage', Import::class);

        return response()->download(base_path('samples/tickets-import.csv'), 'tickets-import-sample.csv');
    }

    public function store(StoreImportRequest $request): RedirectResponse
    {
        $this->authorize('manage', Import::class);

        $import = $this->imports->start($request->file('file'), $request->user());

        return redirect()->route('admin.imports.show', $import);
    }

    public function show(Import $import): Response
    {
        $this->authorize('manage', $import);

        $import->load('importErrors');

        return Inertia::render('admin/imports/show', [
            'import' => new ImportResource($import),
        ]);
    }

    public function errors(Import $import): StreamedResponse
    {
        $this->authorize('manage', $import);

        return response()->streamDownload(function () use ($import) {
            $handle = fopen('php://output', 'w');
            // CRLF per RFC 4180 — some spreadsheet tools mis-render a
            // bare-LF CSV as a single row.
            fputcsv($handle, ['row_number', 'errors'], ',', '"', '\\', "\r\n");

            $import->importErrors()->orderBy('row_number')->each(function ($error) use ($handle) {
                fputcsv($handle, [$error->row_number, implode('; ', $error->errors)], ',', '"', '\\', "\r\n");
            });

            fclose($handle);
        }, "import-{$import->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }
}
