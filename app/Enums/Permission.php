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
    case AuditView = 'audit.view';
    case RolesManage = 'roles.manage';

    /** Shown next to the checkbox in the roles editor (Step 19e). */
    public function label(): string
    {
        return match ($this) {
            self::TicketsViewAny => 'View every ticket',
            self::TicketsViewQueue => 'View own assigned + unassigned tickets (queue)',
            self::TicketsViewOwn => 'View tickets they created',
            self::TicketsCreate => 'Create tickets',
            self::TicketsUpdate => 'Edit tickets they can work on',
            self::TicketsUpdateOwn => 'Edit their own open tickets',
            self::TicketsChangeStatus => 'Change ticket status',
            self::TicketsClaim => 'Claim tickets — makes the user an agent (agent settings, auto-assign)',
            self::TicketsAssign => 'Assign / reassign tickets to agents',
            self::RepliesCreate => 'Post public replies',
            self::NotesCreate => 'Write internal notes',
            self::NotesView => 'See internal notes',
            self::AttachmentsUpload => 'Upload attachments',
            self::DashboardViewGlobal => 'Global dashboard — also receives escalation and new-ticket emails',
            self::DashboardViewOwn => 'Own dashboard',
            self::ImportsManage => 'CSV imports',
            self::SlaManage => 'Edit SLA policies',
            self::BusinessHoursManage => 'Edit business hours and holidays',
            self::CategoriesManage => 'Manage categories',
            self::UsersManage => 'Manage users and see agent performance',
            self::ReportsView => 'All reports',
            self::ReportsViewOwn => 'Own row in the agent report',
            self::ReportsExport => 'Export reports',
            self::MonitoringView => 'System health and Pulse',
            self::AuditView => 'Audit log',
            self::RolesManage => 'Manage roles and permissions',
        };
    }

    /** Heading the permission is grouped under: the part before the dot. */
    public function group(): string
    {
        return strstr($this->value, '.', true) ?: $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
