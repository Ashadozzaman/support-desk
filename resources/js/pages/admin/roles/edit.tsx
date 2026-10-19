import { Head, router, usePage } from '@inertiajs/react';
import { ConfirmDialog } from '@/components/feedback/ConfirmDialog';
import InputError from '@/components/input-error';
import { RoleForm } from '@/components/role/RoleForm';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { destroy, edit, index } from '@/routes/admin/roles';
import type { PermissionGroup, RoleRow } from '@/types/role';

type Props = {
    role: { data: RoleRow };
    permissionGroups: PermissionGroup[];
};

export default function RolesEdit({ role, permissionGroups }: Props) {
    const { errors } = usePage().props;
    const current = role.data;

    return (
        <>
            <Head title={`Edit ${current.label}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <RoleForm mode="edit" role={current} permissionGroups={permissionGroups} />

                {!current.is_system && (
                    <div className="flex flex-col items-end gap-2">
                        <ConfirmDialog
                            trigger={
                                <Button variant="destructive" disabled={current.users_count > 0}>
                                    Delete role
                                </Button>
                            }
                            title={`Delete ${current.label}?`}
                            description="This can't be undone. The change is recorded in the audit log."
                            confirmLabel="Delete"
                            destructive
                            onConfirm={() => router.delete(destroy(current.id).url)}
                        />
                        {current.users_count > 0 && (
                            <p className="text-muted-foreground text-sm">
                                {current.users_count} user(s) still have this role — move them first.
                            </p>
                        )}
                        <InputError message={errors.role} />
                    </div>
                )}
            </div>
        </>
    );
}

RolesEdit.layout = ({ role }: Props) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Roles', href: index() },
        { title: role.data.label, href: edit(role.data.id) },
    ],
});
