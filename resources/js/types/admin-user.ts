export type AdminUserAgent = {
    is_available: boolean;
    max_active_tickets: number;
    category_ids: number[];
};

export type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    is_active: boolean;
    agent: AdminUserAgent | null;
    created_at: string;
};

export type RoleOption = {
    value: string;
    label: string;
    /** The role has `tickets.claim`, so the agent fields apply. */
    works_tickets: boolean;
};

export type UserCategoryOption = {
    id: number;
    name: string;
};

export type UserFilters = {
    search: string | null;
    role: string | null;
};
