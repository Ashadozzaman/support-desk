<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSlaPoliciesRequest;
use App\Http\Resources\SlaPolicyResource;
use App\Models\SlaPolicy;
use App\Services\SlaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SlaPolicyController extends Controller
{
    public function __construct(private readonly SlaService $sla) {}

    public function index(): Response
    {
        $this->authorize('manage', SlaPolicy::class);

        return Inertia::render('admin/sla-policies/index', [
            'policies' => SlaPolicyResource::collection($this->sla->policies()->values()),
        ]);
    }

    public function update(UpdateSlaPoliciesRequest $request): RedirectResponse
    {
        $this->authorize('manage', SlaPolicy::class);

        $this->sla->updatePolicies($request->toData(), $request->user());

        return redirect()->route('admin.sla-policies.index');
    }
}
