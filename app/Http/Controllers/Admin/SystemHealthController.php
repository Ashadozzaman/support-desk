<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function __construct(private readonly SystemHealthService $health) {}

    public function index(): Response
    {
        $this->authorize('viewSystemHealth');

        return Inertia::render('admin/system-health/index', [
            'report' => $this->health->run()->toArray(),
        ]);
    }
}
