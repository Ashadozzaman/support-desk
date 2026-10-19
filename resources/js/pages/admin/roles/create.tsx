import { Head } from '@inertiajs/react';
import { RoleForm } from '@/components/role/RoleForm';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/admin/roles';
import type { PermissionGroup } from '@/types/role';

export default function RolesCreate({ permissionGroups }: { permissionGroups: PermissionGroup[] }) {
    return (
        <>
            <Head title="New role" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <RoleForm mode="create" permissionGroups={permissionGroups} />
            </div>
        </>
    );
}

RolesCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Roles', href: index() },
        { title: 'New', href: create() },
    ],
};
