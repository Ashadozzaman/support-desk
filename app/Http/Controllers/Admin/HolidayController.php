<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHolidayRequest;
use App\Models\Holiday;
use App\Services\SlaService;
use Illuminate\Http\RedirectResponse;

class HolidayController extends Controller
{
    public function __construct(private readonly SlaService $sla) {}

    public function store(StoreHolidayRequest $request): RedirectResponse
    {
        $this->authorize('manage', Holiday::class);

        $this->sla->addHoliday($request->holidayDate(), $request->holidayName());

        return redirect()->route('admin.business-hours.index');
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $this->authorize('manage', Holiday::class);

        $this->sla->removeHoliday($holiday->id);

        return redirect()->route('admin.business-hours.index');
    }
}
