<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Inbound\MailboxClient;
use App\Contracts\Repositories\MailboxRepositoryInterface;
use App\DTOs\MailboxConnectionData;
use App\DTOs\MailboxSettingsData;
use App\Enums\AuditEvent;
use App\Enums\MailboxEncryption;
use App\Exceptions\MailboxConnectionException;
use App\Models\MailboxSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Mailbox (Step 25b, docs/IMAP.md §5). The password is write-only:
 * it is stored encrypted, never returned, and a blank field keeps it.
 */
class MailboxSettingsService
{
    /** Changing any of these points at a different mailbox: its UIDs mean nothing to us. */
    private const MAILBOX_IDENTITY = ['host', 'port', 'username', 'folder'];

    public function __construct(
        private readonly MailboxRepositoryInterface $mailbox,
        private readonly MailboxClient $client,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * The saved settings, or an unsaved row filled from config/support.php
     * (Gmail defaults) before an admin has saved anything.
     */
    public function current(): MailboxSetting
    {
        return $this->mailbox->settings() ?? new MailboxSetting([
            'is_enabled' => false,
            'host' => config('support.mailbox.host'),
            'port' => config('support.mailbox.port'),
            'encryption' => MailboxEncryption::tryFrom((string) config('support.mailbox.encryption')) ?? MailboxEncryption::Ssl,
            'username' => config('support.mailbox.username'),
            'folder' => config('support.mailbox.folder'),
            'fetch_interval_minutes' => config('support.mailbox.fetch_interval_minutes'),
            'batch_size' => config('support.mailbox.batch_size'),
        ]);
    }

    /** Where the password comes from — shown on the page instead of the password. */
    public function passwordSource(MailboxSetting $settings): ?string
    {
        return match (true) {
            $settings->password !== null && $settings->password !== '' => 'saved',
            $this->envPassword() !== '' => 'env',
            default => null,
        };
    }

    /**
     * @throws ValidationException when enabling without any password
     */
    public function update(MailboxSettingsData $data, User $actor): MailboxSetting
    {
        $before = $this->current();
        $attributes = $this->attributesFrom($data);

        if ($data->isEnabled && $data->password === null && $this->passwordSource($before) === null) {
            throw ValidationException::withMessages(['password' => 'Enter the app password before enabling the mailbox.']);
        }

        $changes = $this->diff($before, $attributes);

        if (array_intersect(array_keys($changes['new'] ?? []), self::MAILBOX_IDENTITY) !== []) {
            // New mailbox → the next sync records a fresh starting point.
            $attributes['last_uid'] = null;
            $attributes['uid_validity'] = null;
        }

        return DB::transaction(function () use ($attributes, $changes, $actor) {
            $settings = $this->mailbox->saveSettings($attributes);

            if ($changes !== []) {
                $this->audit->record(AuditEvent::MailboxSettingsUpdated, $actor, properties: ['changes' => ['mailbox' => $changes]]);
            }

            return $settings;
        });
    }

    /**
     * Tries the values in the form (blank password = the stored one) without
     * saving them. Returns null when it connected, otherwise a safe message.
     */
    public function testConnection(MailboxSettingsData $data): ?string
    {
        $attributes = $this->attributesFrom($data);
        $password = $data->password ?? $this->passwordFor($this->current());

        try {
            $this->client->testConnection(new MailboxConnectionData(
                host: $attributes['host'],
                port: $attributes['port'],
                encryption: $attributes['encryption'],
                username: $attributes['username'],
                password: $this->normalisePassword($password),
                folder: $attributes['folder'],
            ));

            return null;
        } catch (MailboxConnectionException $e) {
            Log::warning('mailbox.test_failed', ['host' => $attributes['host'], 'reason' => $e->getMessage(), 'causes' => $e->causes()]);

            return $e->getMessage();
        }
    }

    public function connectionFor(MailboxSetting $settings): MailboxConnectionData
    {
        return new MailboxConnectionData(
            host: $settings->host,
            port: $settings->port,
            encryption: $settings->encryption,
            username: $settings->username,
            password: $this->normalisePassword($this->passwordFor($settings)),
            folder: $settings->folder,
        );
    }

    private function passwordFor(MailboxSetting $settings): string
    {
        return $settings->password !== null && $settings->password !== '' ? $settings->password : $this->envPassword();
    }

    private function envPassword(): string
    {
        return (string) config('support.mailbox.password');
    }

    /** Google shows app passwords as "abcd efgh ijkl mnop"; the spaces aren't part of it. */
    private function normalisePassword(string $password): string
    {
        return str_replace(' ', '', $password);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFrom(MailboxSettingsData $data): array
    {
        $attributes = [
            'is_enabled' => $data->isEnabled,
            'host' => $data->host,
            'port' => $data->port,
            'encryption' => $data->encryption,
            'username' => $data->username,
            'folder' => $data->folder,
            'fetch_interval_minutes' => $data->fetchIntervalMinutes,
            'batch_size' => $data->batchSize,
        ];

        if ($data->password !== null) {
            $attributes['password'] = $data->password;
        }

        return $attributes;
    }

    /**
     * Field-level old/new for the audit log; the password only as "changed".
     *
     * @param  array<string, mixed>  $attributes
     * @return array{old?: array<string, mixed>, new?: array<string, mixed>}
     */
    private function diff(MailboxSetting $before, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $field => $value) {
            if ($field === 'password') {
                if ($value !== $before->password) {
                    $changes['old']['password'] = $before->password === null ? 'not set' : 'hidden';
                    $changes['new']['password'] = 'changed';
                }

                continue;
            }

            $old = $before->exists ? $before->getAttribute($field) : null;
            $oldValue = $old instanceof MailboxEncryption ? $old->value : $old;
            $newValue = $value instanceof MailboxEncryption ? $value->value : $value;

            if ($oldValue != $newValue) {
                $changes['old'][$field] = $oldValue;
                $changes['new'][$field] = $newValue;
            }
        }

        return $changes;
    }
}
