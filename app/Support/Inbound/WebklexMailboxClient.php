<?php

declare(strict_types=1);

namespace App\Support\Inbound;

use App\Contracts\Inbound\MailboxClient;
use App\DTOs\MailboxConnectionData;
use App\DTOs\MailboxFetchResult;
use App\Enums\MailboxEncryption;
use App\Exceptions\MailboxConnectionException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\IMAP;

class WebklexMailboxClient implements MailboxClient
{
    public function testConnection(MailboxConnectionData $connection): void
    {
        $this->withFolder($connection, fn (Folder $folder) => $folder->examine());
    }

    public function fetch(MailboxConnectionData $connection, ?int $afterUid, int $limit): MailboxFetchResult
    {
        return $this->withFolder($connection, function (Folder $folder) use ($afterUid, $limit) {
            $status = $folder->examine();
            $uidValidity = (int) ($status['uidvalidity'] ?? 0);
            $highestUid = max(0, (int) ($status['uidnext'] ?? 1) - 1);

            if ($afterUid === null || $afterUid >= $highestUid) {
                return new MailboxFetchResult($uidValidity, $highestUid);
            }

            // Raw UID SEARCH on the connection: the query builder quotes any non-numeric value,
            // and Gmail rejects `UID "1160:*"` as a bad command.
            $uids = $folder->getClient()->getConnection()
                ->search(['UID '.($afterUid + 1).':*'], IMAP::ST_UID)
                ->validatedData();

            // "UID n:*" always matches the newest message, even below n (RFC 3501 §6.4.8).
            $uids = array_filter(array_map('intval', (array) $uids), fn (int $uid) => $uid > $afterUid);
            sort($uids);

            $query = $folder->query()->leaveUnread();
            $messages = [];

            foreach (array_slice($uids, 0, $limit) as $uid) {
                $message = $query->getMessageByUid($uid);
                $messages[$uid] = rtrim((string) $message->getHeader()?->raw)."\r\n\r\n".$message->getRawBody();
            }

            ksort($messages);

            return new MailboxFetchResult($uidValidity, $highestUid, $messages);
        });
    }

    /**
     * @template T
     *
     * @param  callable(Folder): T  $callback
     * @return T
     */
    private function withFolder(MailboxConnectionData $connection, callable $callback): mixed
    {
        $client = null;

        try {
            $client = $this->connect($connection);
            $folder = $client->getFolderByPath($connection->folder, soft_fail: true)
                ?? throw MailboxConnectionException::folderNotFound($connection->folder);

            return $callback($folder);
        } catch (MailboxConnectionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw MailboxConnectionException::from($e, $connection->host, $connection->port, $connection->folder);
        } finally {
            try {
                $client?->disconnect();
            } catch (Throwable) {
                // Already gone; nothing to clean up.
            }
        }
    }

    private function connect(MailboxConnectionData $connection): Client
    {
        // A library object built per call from the admin's settings (not a service, so not injected).
        $client = (new ClientManager)->make([
            'host' => $connection->host,
            'port' => $connection->port,
            'encryption' => $connection->encryption === MailboxEncryption::None ? false : $connection->encryption->value,
            'validate_cert' => true,
            'username' => $connection->username,
            'password' => $connection->password,
            'protocol' => 'imap',
            'timeout' => config('support.mailbox.timeout'),
        ]);

        return $client->connect();
    }
}
