<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Import;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
 * All channels are private — authorised here server-side
 * (QUEUE_SCHEDULER_REALTIME.md §4). Internal-note and escalation traffic is
 * only ever broadcast to the `.staff` channel, so a customer who can never
 * authorise it can never receive it, even if a listener mistakenly fired on
 * the public channel too.
 */

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('tickets.{ticket}', function (User $user, Ticket $ticket) {
    return Gate::forUser($user)->allows('view', $ticket);
});

Broadcast::channel('tickets.{ticket}.staff', function (User $user, Ticket $ticket) {
    return $user->can(Permission::NotesView->value) && Gate::forUser($user)->allows('view', $ticket);
});

Broadcast::channel('staff.queue', function (User $user) {
    return $user->can(Permission::TicketsClaim->value);
});

Broadcast::channel('staff.alerts', function (User $user) {
    return $user->can(Permission::DashboardViewGlobal->value);
});

Broadcast::channel('imports.{import}', function (User $user, Import $import) {
    return $import->user_id === $user->id;
});
