<?php

declare(strict_types=1);

namespace App\Contracts\Inbound;

use App\DTOs\MailboxConnectionData;
use App\DTOs\MailboxFetchResult;
use App\Exceptions\MailboxConnectionException;

/**
 * IMAP access behind an interface, so tests use FakeMailboxClient and never
 * need a real mailbox (docs/IMAP.md §2).
 */
interface MailboxClient
{
    /**
     * Logs in and opens the folder; nothing is read.
     *
     * @throws MailboxConnectionException
     */
    public function testConnection(MailboxConnectionData $connection): void;

    /**
     * The folder's UIDVALIDITY and highest UID, plus up to $limit messages
     * with a UID above $afterUid (none when $afterUid is null). Messages are
     * read with PEEK: their read/unread state in the mailbox is unchanged.
     *
     * @throws MailboxConnectionException
     */
    public function fetch(MailboxConnectionData $connection, ?int $afterUid, int $limit): MailboxFetchResult;
}
