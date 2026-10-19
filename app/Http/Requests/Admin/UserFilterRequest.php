<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\UserFilterData;
use Illuminate\Foundation\Http\FormRequest;

class UserFilterRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'exists:roles,name'],
        ];
    }

    public function toData(): UserFilterData
    {
        return new UserFilterData(
            search: $this->filled('search') ? $this->string('search')->trim()->value() : null,
            role: $this->filled('role') ? $this->string('role')->value() : null,
        );
    }

    /**
     * @return array{search: ?string, role: ?string}
     */
    public function filters(): array
    {
        $data = $this->toData();

        return ['search' => $data->search, 'role' => $data->role];
    }
}
