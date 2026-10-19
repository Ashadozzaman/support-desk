<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three built-in ("system") roles. Other roles can be created in the
 * roles editor (Step 19e) and live only in the database. There is no
 * `users.role` column.
 *
 * Code never authorizes by these names — only by permission. They are
 * kept because seeding and registration (new sign-ups become customers)
 * refer to them, which is also why they can't be renamed or deleted.
 */
enum Role: string
{
    case Admin = 'admin';
    case Agent = 'agent';
    case Customer = 'customer';

    public static function isSystem(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }
}
