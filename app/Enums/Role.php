<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Source of spatie role-name strings only — there is no `users.role` column.
 */
enum Role: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Customer = 'customer';
}
