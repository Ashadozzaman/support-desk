<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\MailboxSettingsData;
use App\Enums\MailboxEncryption;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Save and Test connection send the same form (docs/IMAP.md §2). */
class MailboxSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::enum(MailboxEncryption::class)],
            'username' => ['required', 'string', 'max:255'],
            // Blank = keep the stored one.
            'password' => ['nullable', 'string', 'max:255'],
            'folder' => ['required', 'string', 'max:255'],
            'fetch_interval_minutes' => ['required', 'integer', 'between:1,1440'],
            'batch_size' => ['required', 'integer', 'between:1,200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['host.regex' => 'Enter a host name only, e.g. imap.gmail.com (no "imap://" or port).'];
    }

    public function toData(): MailboxSettingsData
    {
        $password = $this->string('password')->trim()->value();

        return new MailboxSettingsData(
            isEnabled: $this->boolean('is_enabled'),
            host: $this->string('host')->trim()->lower()->value(),
            port: $this->integer('port'),
            encryption: MailboxEncryption::from($this->string('encryption')->value()),
            username: $this->string('username')->trim()->value(),
            password: $password === '' ? null : $password,
            folder: $this->string('folder')->trim()->value(),
            fetchIntervalMinutes: $this->integer('fetch_interval_minutes'),
            batchSize: $this->integer('batch_size'),
        );
    }
}
