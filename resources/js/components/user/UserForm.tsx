import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { CheckboxGroupInput } from '@/components/form/CheckboxGroupInput';
import { CheckboxInput } from '@/components/form/CheckboxInput';
import { SelectInput } from '@/components/form/SelectInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import { store, update } from '@/routes/admin/users';
import type { AdminUser, RoleOption, UserCategoryOption } from '@/types/admin-user';

type UserFormValues = {
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    agent: {
        is_available: boolean;
        max_active_tickets: number;
        category_ids: number[];
    };
};

/**
 * Shared create/edit form. The agent section appears only for a role that
 * can work tickets; the server makes the same decision on its own.
 */
export function UserForm({
    mode,
    user,
    roles,
    categories,
}: {
    mode: 'create' | 'edit';
    user?: AdminUser;
    roles: RoleOption[];
    categories: UserCategoryOption[];
}) {
    const form = useForm<UserFormValues>({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? '',
        is_active: user?.is_active ?? true,
        agent: {
            is_available: user?.agent?.is_available ?? true,
            max_active_tickets: user?.agent?.max_active_tickets ?? 10,
            category_ids: user?.agent?.category_ids ?? [],
        },
    });

    const worksTickets = roles.find((role) => role.value === form.data.role)?.works_tickets ?? false;
    const errors = form.errors as Partial<Record<string, string>>;

    function setAgent<K extends keyof UserFormValues['agent']>(key: K, value: UserFormValues['agent'][K]) {
        form.setData('agent', { ...form.data.agent, [key]: value });
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (mode === 'create') {
            form.post(store().url);
        } else if (user) {
            form.put(update(user.id).url);
        }
    }

    return (
        <FormCard
            title={mode === 'create' ? 'New user' : `Edit ${user?.name ?? 'user'}`}
            description={
                mode === 'create'
                    ? 'They will get an email with a link to set their own password.'
                    : 'Changes are recorded in the audit log.'
            }
            onSubmit={submit}
            footer={
                <SubmitButton processing={form.processing}>
                    {mode === 'create' ? 'Create user' : 'Save changes'}
                </SubmitButton>
            }
        >
            <TextInput
                name="name"
                label="Name"
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value)}
                error={form.errors.name}
                maxLength={255}
                required
            />

            <TextInput
                name="email"
                label="Email"
                type="email"
                value={form.data.email}
                onChange={(event) => form.setData('email', event.target.value)}
                error={form.errors.email}
                maxLength={255}
                required
            />

            <SelectInput
                name="role"
                label="Role"
                value={form.data.role}
                placeholder="Choose a role…"
                options={roles.map((role) => ({ value: role.value, label: role.label }))}
                onChange={(value) => form.setData('role', value)}
                error={form.errors.role}
            />

            <CheckboxInput
                name="is_active"
                label="Active (can sign in)"
                checked={form.data.is_active}
                onChange={(checked) => form.setData('is_active', checked)}
                error={form.errors.is_active}
            />

            {worksTickets && (
                <div className="space-y-6 rounded-lg border p-4">
                    <div>
                        <h3 className="font-medium">Agent settings</h3>
                        <p className="text-muted-foreground text-sm">Used by automatic assignment.</p>
                    </div>

                    <CheckboxInput
                        name="agent.is_available"
                        label="Available for automatic assignment"
                        checked={form.data.agent.is_available}
                        onChange={(checked) => setAgent('is_available', checked)}
                        error={errors['agent.is_available']}
                    />

                    <TextInput
                        name="agent.max_active_tickets"
                        label="Max active tickets"
                        type="number"
                        min={1}
                        max={1000}
                        value={form.data.agent.max_active_tickets}
                        onChange={(event) => setAgent('max_active_tickets', Number(event.target.value))}
                        error={errors['agent.max_active_tickets']}
                        required
                    />

                    <CheckboxGroupInput
                        name="agent.category_ids"
                        label="Categories"
                        description="Leave all unticked to handle every category."
                        options={categories.map((category) => ({ value: category.id, label: category.name }))}
                        value={form.data.agent.category_ids}
                        onChange={(value) => setAgent('category_ids', value)}
                        error={errors['agent.category_ids']}
                    />
                </div>
            )}
        </FormCard>
    );
}
