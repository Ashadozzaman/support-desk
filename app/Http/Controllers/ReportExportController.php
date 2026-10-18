<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\ReportExport;
use App\Services\ReportExportService;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports are GET downloads (a link the browser saves), throttled in the
 * route. The ticket export may create a report_exports row + queued job
 * instead of a file — a side effect on GET, accepted so the download stays a
 * plain link; the throttle bounds it.
 */
class ReportExportController extends Controller
{
    public function __construct(private readonly ReportExportService $exports) {}

    public function summary(ReportFilterRequest $request): StreamedResponse
    {
        $this->authorize('exportReports');
        $tab = $request->tab();
        $this->authorize('viewReportTab', $tab);

        $summary = $this->exports->summary($tab, $request->user(), $request->toData());

        return $this->stream($summary['filename'], LazyCollection::make($summary['rows']));
    }

    public function tickets(ReportFilterRequest $request): StreamedResponse|RedirectResponse
    {
        $this->authorize('exportReports');

        $data = $request->toData();
        $plan = $this->exports->tickets($request->user(), $data);

        if ($plan->queued !== null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Large export queued — you\'ll get a notification with the download link.']);

            return back();
        }

        $rows = LazyCollection::make(fn () => yield ReportExportService::TICKET_HEADER)->concat($plan->rows ?? []);

        return $this->stream("tickets-{$data->from->toDateString()}_{$data->to->toDateString()}.csv", $rows);
    }

    public function download(ReportExport $export): StreamedResponse
    {
        $this->authorize('download', $export);

        return Storage::disk('local')->download(
            (string) $export->disk_path,
            "tickets-export-{$export->id}.csv",
            ['Content-Type' => 'text/csv', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * @param  LazyCollection<int, array<int, string|int|float|null>>  $rows
     */
    private function stream(string $filename, LazyCollection $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $handle = Csv::open('php://output');

            foreach ($rows as $row) {
                Csv::writeRow($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv', 'X-Content-Type-Options' => 'nosniff']);
    }
}
