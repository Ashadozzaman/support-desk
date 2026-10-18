<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSlaPoliciesRequest extends FormRequest
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
            'policies' => ['required', 'array', 'size:'.count(TicketPriority::cases())],
            'policies.*.priority' => ['required', Rule::enum(TicketPriority::class)],
            'policies.*.resolution_minutes' => ['required', 'integer', 'min:1'],
            'policies.*.warning_threshold_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'policies.*.is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, array{resolution_minutes: int, warning_threshold_percent: int, is_active: bool}>
     */
    public function toData(): array
    {
        $rows = [];

        foreach ($this->validated('policies') as $row) {
            $rows[$row['priority']] = [
                'resolution_minutes' => (int) $row['resolution_minutes'],
                'warning_threshold_percent' => (int) $row['warning_threshold_percent'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return $rows;
    }
}
