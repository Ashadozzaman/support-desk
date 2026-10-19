import { Link } from '@inertiajs/react';
import { DataTable } from '@/components/data/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/admin/users';
import type { AdminUser, RoleOption } from '@/types/admin-user';

export function UserTable({ users, roles }: { users: AdminUser[]; roles: RoleOption[] }) {
    const roleLabel = (role: string | null) => roles.find((option) => option.value === role)?.label ?? role ?? '—';

    return (
        <DataTable
            rows={users}
            rowKey={(user) => user.id}
            columns={[
                {
                    header: 'Name',
                    cell: (user) => (
                        <div>
                            <div className="font-medium">{user.name}</div>
                            <div className="text-muted-foreground text-xs">{user.email}</div>
                        </div>
                    ),
                },
                { header: 'Role', cell: (user) => roleLabel(user.role) },
                {
                    header: 'Status',
                    cell: (user) => (
                        <Badge variant={user.is_active ? 'secondary' : 'destructive'}>
                            {user.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    ),
                },
                {
                    header: 'Agent',
                    cell: (user) =>
                        user.agent
                            ? `${user.agent.is_available ? 'Available' : 'Unavailable'} · max ${user.agent.max_active_tickets}`
                            : '—',
                },
                {
                    header: '',
                    cell: (user) => (
                        <Button asChild variant="outline" size="sm">
                            <Link href={edit(user.id)}>Edit</Link>
                        </Button>
                    ),
                },
            ]}
        />
    );
}
