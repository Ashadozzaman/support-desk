<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Permission as PermissionEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        return Inertia::render('admin/roles/index', [
            'roles' => RoleResource::collection($this->roles->all()),
            'permissionCount' => count(PermissionEnum::cases()),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('admin/roles/create', [
            'permissionGroups' => $this->roles->permissionGroups(),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $this->roles->create($request->toData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role created.']);

        return redirect()->route('admin.roles.index');
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);

        return Inertia::render('admin/roles/edit', [
            'role' => RoleResource::make($this->roles->findForEdit($role)),
            'permissionGroups' => $this->roles->permissionGroups(),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $this->roles->update($role, $request->toData(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role updated.']);

        return redirect()->route('admin.roles.index');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $this->roles->delete($role, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role deleted.']);

        return redirect()->route('admin.roles.index');
    }
}
