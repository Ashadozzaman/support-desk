<?php

declare(strict_types=1);

namespace App\Support\Inbound;

use App\Contracts\Inbound\MailboxClient;
use App\DTOs\MailboxConnectionData;
use App\DTOs\MailboxFetchResult;
use App\Exceptions\MailboxConnectionException;

/** In-memory mailbox for tests: no network, no credentials. */
class FakeMailboxClient implements MailboxClient
{
    /** @var array<int, string> UID => raw message */
    public array $messages = [];

    public int $uidValidity = 1;

    public ?string $failWith = null;

    /** @var list<MailboxConnectionData> */
    public array $connections = [];

    public function deliver(string $raw): int
    {
        $uid = ($this->messages === [] ? 0 : max(array_keys($this->messages))) + 1;
        $this->messages[$uid] = $raw;

        return $uid;
    }

    public function testConnection(MailboxConnectionData $connection): void
    {
        $this->connect($connection);
    }

    public function fetch(MailboxConnectionData $connection, ?int $afterUid, int $limit): MailboxFetchResult
    {
        $this->connect($connection);
        $highestUid = $this->messages === [] ? 0 : max(array_keys($this->messages));

        if ($afterUid === null) {
            return new MailboxFetchResult($this->uidValidity, $highestUid);
        }

        $newer = array_filter($this->messages, fn (int $uid) => $uid > $afterUid, ARRAY_FILTER_USE_KEY);
        ksort($newer);

        return new MailboxFetchResult($this->uidValidity, $highestUid, array_slice($newer, 0, $limit, preserve_keys: true));
    }

    private function connect(MailboxConnectionData $connection): void
    {
        $this->connections[] = $connection;

        if ($this->failWith !== null) {
            throw new MailboxConnectionException($this->failWith);
        }
    }
}
