<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A roles-editor change that isn't allowed: editing the admin role,
 * renaming or deleting a built-in role, deleting a role that still has
 * users, or removing the last active holder of users.manage/roles.manage.
 * Rendered as a 422 validation error on `$field` (bootstrap/app.php).
 */
class ProtectedRoleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
