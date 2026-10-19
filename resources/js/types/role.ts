export type RoleRow = {
    id: number;
    name: string;
    label: string;
    /** admin / agent / customer: can't be renamed or deleted. */
    is_system: boolean;
    /** admin: always every permission, not editable. */
    is_locked: boolean;
    users_count: number;
    permissions: string[];
};

export type PermissionGroup = {
    group: string;
    /** Heading, e.g. "Business Hours". */
    label: string;
    permissions: { value: string; label: string }[];
};
