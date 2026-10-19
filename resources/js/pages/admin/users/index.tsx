import { Head, Link, router, usePage } from '@inertiajs/react';
import { UsersIcon } from 'lucide-react';
import { FilterCard } from '@/components/cards/FilterCard';
import { EmptyState } from '@/components/data/EmptyState';
import { Pagination } from '@/components/data/Pagination';
import { SearchInput } from '@/components/form/SearchInput';
import { SelectInput } from '@/components/form/SelectInput';
import { Button } from '@/components/ui/button';
import { UserTable } from '@/components/user/UserTable';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/admin/users';
import type { AdminUser, RoleOption, UserFilters } from '@/types/admin-user';
import type { Paginated } from '@/types/pagination';

const ALL = '';

export default function UsersIndex({
    users,
    filters,
    roles,
}: {
    users: Paginated<AdminUser>;
    filters: UserFilters;
    roles: RoleOption[];
}) {
    const { errors } = usePage().props;

    function apply(next: Partial<UserFilters>) {
        const query = Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, value]) => value));

        router.get(index({ query }).url, {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    return (
        <>
            <Head title="Users" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-lg font-semibold">Users</h1>
                        <p className="text-muted-foreground text-sm">Add agents, change roles, deactivate accounts.</p>
                    </div>
                    <Button asChild>
                        <Link href={create()}>New user</Link>
                    </Button>
                </div>

                <FilterCard>
                    <SearchInput
                        value={filters.search ?? ''}
                        onSearch={(search) => {
                            if (search !== (filters.search ?? '')) {
                                apply({ search: search || null });
                            }
                        }}
                        placeholder="Name or email…"
                        className="w-full sm:w-64"
                    />
                    <div className="w-full sm:w-44">
                        <SelectInput
                            name="role"
                            label="Role"
                            value={filters.role ?? ALL}
                            error={errors.role}
                            options={[{ value: ALL, label: 'All roles' }, ...roles.map(({ value, label }) => ({ value, label }))]}
                            onChange={(value) => apply({ role: value || null })}
                        />
                    </div>
                </FilterCard>

                {users.data.length === 0 ? (
                    <EmptyState icon={UsersIcon} title="No users found" description="Try a different search or role." />
                ) : (
                    <UserTable users={users.data} roles={roles} />
                )}

                <Pagination meta={users.meta} />
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
    ],
};
