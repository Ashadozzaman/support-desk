<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $notifications = app(NotificationService::class);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'roles' => $user?->getRoleNames() ?? [],
                'permissions' => $user?->getAllPermissions()->pluck('name') ?? [],
            ],
            // Sidebar AI links (cosmetic — the routes check `ai.enabled` and the
            // permission themselves). The customer help chat is hidden for staff
            // (anyone with the data assistant): admin holds every permission, but
            // the link is clutter there. Staff can still open it from the Help
            // centre card to try the customer experience.
            'aiChatAvailable' => $user !== null && (bool) config('ai.enabled')
                && $user->can(Permission::AiChat->value) && ! $user->can(Permission::AiUseAssistant->value),
            'aiDataAssistantAvailable' => $user !== null && (bool) config('ai.enabled') && $user->can(Permission::AiUseAssistant->value),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'notifications' => $user === null ? null : fn () => [
                'unreadCount' => $notifications->unreadCountFor($user),
                // A plain array of resources, not ::collection() — a
                // ResourceCollection JSON-encodes wrapped in a "data" key,
                // which breaks a prop that is itself a plain list. Each item
                // must be ->resolve()'d to a plain array: Inertia's prop
                // resolver recurses into array values and wraps any bare
                // JsonResource it finds in "data" at any depth, so leaving
                // these as resource objects double-wraps every row.
                'recent' => $notifications->recentFor($user)->map(fn ($n) => (new NotificationResource($n))->resolve())->all(),
            ],
        ];
    }
}
