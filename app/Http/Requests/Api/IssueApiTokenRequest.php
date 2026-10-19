<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\DTOs\IssueApiTokenData;
use Illuminate\Foundation\Http\FormRequest;

class IssueApiTokenRequest extends FormRequest
{
    /**
     * Nobody is authenticated yet — the credentials are the authorization.
     */
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            // Shown back to the user when listing/revoking tokens, e.g. "CI bot".
            'device_name' => ['required', 'string', 'max:100'],
            // TOTP code, required only for accounts with two-factor enabled.
            'code' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function toData(): IssueApiTokenData
    {
        return new IssueApiTokenData(
            email: $this->string('email')->value(),
            password: $this->string('password')->value(),
            deviceName: $this->string('device_name')->value(),
            code: $this->filled('code') ? $this->string('code')->value() : null,
            ip: $this->ip(),
            userAgent: $this->userAgent(),
        );
    }
}
