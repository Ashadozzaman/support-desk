<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'items' => NotificationResource::collection($this->notifications->paginateFor($request->user())),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($request->user());

        return back();
    }
}
