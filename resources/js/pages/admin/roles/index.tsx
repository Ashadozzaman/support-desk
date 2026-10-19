import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { RoleTable } from '@/components/role/RoleTable';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/admin/roles';
import type { RoleRow } from '@/types/role';

export default function RolesIndex({ roles, permissionCount }: { roles: { data: RoleRow[] }; permissionCount: number }) {
    return (
        <>
            <Head title="Roles" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Roles</h1>
                        <p className="text-muted-foreground text-sm">
                            Combine permissions into roles, then give users a role on the Users page.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href={create()}>New role</Link>
                    </Button>
                </div>

                <RoleTable roles={roles.data} permissionCount={permissionCount} />
            </div>
        </>
    );
}

RolesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Roles', href: index() },
    ],
};
