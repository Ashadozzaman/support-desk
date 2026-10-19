import { Head } from '@inertiajs/react';
import { UserForm } from '@/components/user/UserForm';
import { dashboard } from '@/routes';
import { edit, index } from '@/routes/admin/users';
import type { AdminUser, RoleOption, UserCategoryOption } from '@/types/admin-user';

type Props = {
    user: { data: AdminUser };
    roles: RoleOption[];
    categories: UserCategoryOption[];
};

export default function UsersEdit({ user, roles, categories }: Props) {
    return (
        <>
            <Head title={`Edit ${user.data.name}`} />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <UserForm mode="edit" user={user.data} roles={roles} categories={categories} />
            </div>
        </>
    );
}

UsersEdit.layout = ({ user }: Props) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
        { title: user.data.name, href: edit(user.data.id) },
    ],
});
