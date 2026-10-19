<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Admin\UserFilterRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function index(UserFilterRequest $request): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('admin/users/index', [
            'users' => AdminUserResource::collection($this->users->paginate($request->toData(), 25)),
            'filters' => $request->filters(),
            'roles' => $this->users->roleOptions(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('admin/users/create', $this->users->formOptions());
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $this->users->create($request->toData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User created — a link to set their password has been emailed.']);

        return redirect()->route('admin.users.index');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('admin/users/edit', [
            'user' => AdminUserResource::make($this->users->findForEdit($user)),
            ...$this->users->formOptions(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->users->update($user, $request->toData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'User updated.']);

        return redirect()->route('admin.users.index');
    }
}
