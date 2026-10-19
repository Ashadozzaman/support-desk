import { Head } from '@inertiajs/react';
import { UserForm } from '@/components/user/UserForm';
import { dashboard } from '@/routes';
import { create, index } from '@/routes/admin/users';
import type { RoleOption, UserCategoryOption } from '@/types/admin-user';

export default function UsersCreate({ roles, categories }: { roles: RoleOption[]; categories: UserCategoryOption[] }) {
    return (
        <>
            <Head title="New user" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <UserForm mode="create" roles={roles} categories={categories} />
            </div>
        </>
    );
}

UsersCreate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
        { title: 'New', href: create() },
    ],
};
