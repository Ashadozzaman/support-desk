<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An admin edit that would lock people out of user management: changing
 * your own role, deactivating yourself, or removing the last active user
 * who holds `users.manage`. Rendered as a 422 validation error on `$field`
 * (bootstrap/app.php) so the form shows it next to the field.
 */
class UserLockoutException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
