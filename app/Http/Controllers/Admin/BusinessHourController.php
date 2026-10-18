<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBusinessHoursRequest;
use App\Http\Resources\BusinessHourResource;
use App\Http\Resources\HolidayResource;
use App\Models\BusinessHour;
use App\Services\SlaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessHourController extends Controller
{
    public function __construct(private readonly SlaService $sla) {}

    public function index(): Response
    {
        $this->authorize('manage', BusinessHour::class);

        return Inertia::render('admin/business-hours/index', [
            'hours' => BusinessHourResource::collection($this->sla->businessHours()->values()->sortBy('day_of_week')->values()),
            'holidays' => HolidayResource::collection($this->sla->holidays()),
        ]);
    }

    public function update(UpdateBusinessHoursRequest $request): RedirectResponse
    {
        $this->authorize('manage', BusinessHour::class);

        $this->sla->updateBusinessHours($request->toData());

        return redirect()->route('admin.business-hours.index');
    }
}
