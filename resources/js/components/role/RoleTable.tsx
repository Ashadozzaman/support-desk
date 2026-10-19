import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/data/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/admin/roles';
import type { RoleRow } from '@/types/role';

export function RoleTable({ roles, permissionCount }: { roles: RoleRow[]; permissionCount: number }) {
    return (
        <DataTable
            rows={roles}
            rowKey={(role) => role.id}
            columns={[
                {
                    header: 'Role',
                    cell: (role) => (
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{role.label}</span>
                            {role.is_system && <Badge variant="secondary">Built-in</Badge>}
                        </div>
                    ),
                },
                { header: 'Users', numeric: true, cell: (role) => role.users_count },
                {
                    header: 'Permissions',
                    numeric: true,
                    cell: (role) => `${role.permissions.length} / ${permissionCount}`,
                },
                {
                    header: '',
                    cell: (role) => (
                        <Button asChild variant="outline" size="sm">
                            <Link href={edit(role.id)}>{role.is_locked ? 'View' : 'Edit'}</Link>
                        </Button>
                    ),
                },
            ]}
        />
    );
}
