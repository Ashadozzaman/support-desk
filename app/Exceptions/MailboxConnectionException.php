<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\FolderFetchingException;

/**
 * The mailbox couldn't be reached. The message is one of a few fixed,
 * admin-safe sentences: the library's own messages can echo the server's
 * response, so they are never shown, stored or logged.
 */
class MailboxConnectionException extends RuntimeException
{
    public static function from(Throwable $e, string $host, int $port, string $folder): self
    {
        $message = match (true) {
            $e instanceof AuthFailedException => 'Authentication failed — check the username and the app password.',
            $e instanceof FolderFetchingException => "Folder \"{$folder}\" was not found in the mailbox.",
            $e instanceof ConnectionFailedException => "Could not connect to {$host}:{$port} — check host, port and encryption.",
            default => 'The mailbox returned an unexpected error.',
        };

        return new self($message, previous: $e);
    }

    /**
     * Class names and locations of the underlying exceptions, for the log —
     * never their messages.
     *
     * @return list<string>
     */
    public function causes(): array
    {
        $causes = [];

        for ($e = $this->getPrevious(); $e !== null; $e = $e->getPrevious()) {
            $causes[] = $e::class.' at '.basename($e->getFile()).':'.$e->getLine();
        }

        return $causes;
    }

    public static function folderNotFound(string $folder): self
    {
        return new self("Folder \"{$folder}\" was not found in the mailbox.");
    }
}
