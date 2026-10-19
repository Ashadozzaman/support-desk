<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A token request that can't be honoured (wrong credentials, inactive or
 * unverified account, missing/wrong two-factor code). Rendered as a 422
 * validation error on `$field` (bootstrap/app.php), like the login form.
 */
class InvalidApiCredentialsException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
