import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormCard } from '@/components/cards/FormCard';
import { CheckboxGroupInput } from '@/components/form/CheckboxGroupInput';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import InputError from '@/components/input-error';
import { store, update } from '@/routes/admin/roles';
import type { PermissionGroup, RoleRow } from '@/types/role';

type RoleFormValues = {
    name: string;
    permissions: string[];
};

/**
 * Shared create/edit form. Built-in roles keep their name; the admin role
 * is read-only. The server enforces both — this only avoids offering it.
 */
export function RoleForm({
    mode,
    role,
    permissionGroups,
}: {
    mode: 'create' | 'edit';
    role?: RoleRow;
    permissionGroups: PermissionGroup[];
}) {
    const form = useForm<RoleFormValues>({
        name: role?.name ?? '',
        permissions: role?.permissions ?? [],
    });

    const locked = role?.is_locked ?? false;

    // Each group's checkboxes edit only that group's slice of the list.
    function setGroup(group: PermissionGroup, selected: string[]) {
        const inGroup = new Set(group.permissions.map((permission) => permission.value));
        form.setData('permissions', [...form.data.permissions.filter((value) => !inGroup.has(value)), ...selected]);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        if (mode === 'create') {
            form.post(store().url);
        } else if (role) {
            form.put(update(role.id).url);
        }
    }

    return (
        <FormCard
            title={mode === 'create' ? 'New role' : `Edit ${role?.label ?? 'role'}`}
            description={
                locked
                    ? 'The admin role always has every permission.'
                    : 'Permissions take effect on each user’s next request. Changes are recorded in the audit log.'
            }
            onSubmit={submit}
            footer={
                locked ? undefined : (
                    <SubmitButton processing={form.processing}>
                        {mode === 'create' ? 'Create role' : 'Save changes'}
                    </SubmitButton>
                )
            }
        >
            <TextInput
                name="name"
                label="Name"
                value={form.data.name}
                onChange={(event) => form.setData('name', event.target.value.toLowerCase())}
                error={form.errors.name}
                placeholder="e.g. senior-agent"
                maxLength={50}
                disabled={role?.is_system ?? false}
                required
            />

            <div className="grid gap-6">
                {permissionGroups.map((group) => (
                    <CheckboxGroupInput
                        key={group.group}
                        name={`permissions-${group.group}`}
                        label={group.label}
                        options={group.permissions}
                        value={form.data.permissions.filter((value) =>
                            group.permissions.some((permission) => permission.value === value),
                        )}
                        onChange={(selected) => setGroup(group, selected)}
                        disabled={locked}
                    />
                ))}
                <InputError message={form.errors.permissions} />
            </div>
        </FormCard>
    );
}
