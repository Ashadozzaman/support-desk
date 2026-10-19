<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Source of spatie permission-name strings (ARCHITECTURE.md §3). Guard is
 * always 'web' — this project has no API guard.
 */
enum Permission: string
{
    case TicketsViewAny = 'tickets.view-any';
    case TicketsViewQueue = 'tickets.view-queue';
    case TicketsViewOwn = 'tickets.view-own';
    case TicketsCreate = 'tickets.create';
    case TicketsUpdate = 'tickets.update';
    case TicketsUpdateOwn = 'tickets.update-own';
    case TicketsChangeStatus = 'tickets.change-status';
    case TicketsClaim = 'tickets.claim';
    case TicketsAssign = 'tickets.assign';
    case RepliesCreate = 'replies.create';
    case NotesCreate = 'notes.create';
    case NotesView = 'notes.view';
    case AttachmentsUpload = 'attachments.upload';
    case DashboardViewGlobal = 'dashboard.view-global';
    case DashboardViewOwn = 'dashboard.view-own';
    case ImportsManage = 'imports.manage';
    case SlaManage = 'sla.manage';
    case BusinessHoursManage = 'business-hours.manage';
    case CategoriesManage = 'categories.manage';
    case UsersManage = 'users.manage';
    case ReportsView = 'reports.view';
    case ReportsViewOwn = 'reports.view-own';
    case ReportsExport = 'reports.export';
    case MonitoringView = 'monitoring.view';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
