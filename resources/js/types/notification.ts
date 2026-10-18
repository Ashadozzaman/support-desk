export type AppNotification = {
    id: string;
    data: {
        ticket_number?: string;
        message: string;
        url: string;
        /** The url is a file download (e.g. a report export), not a page. */
        download?: boolean;
    };
    read_at: string | null;
    created_at: string;
};

export type NotificationSummary = {
    unreadCount: number;
    recent: AppNotification[];
};
