export type AppNotification = {
    id: string;
    data: {
        ticket_number?: string;
        message: string;
        url: string;
    };
    read_at: string | null;
    created_at: string;
};

export type NotificationSummary = {
    unreadCount: number;
    recent: AppNotification[];
};
