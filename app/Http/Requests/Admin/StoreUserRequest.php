<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\AgentProfileData;
use App\DTOs\UserData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared by create and edit (UpdateUserRequest only relaxes the unique
 * email rule for the user being edited). The agent fields are always
 * validated; the service applies them only for a role that works tickets.
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', $this->uniqueEmail()],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            'is_active' => ['required', 'boolean'],
            'agent.is_available' => ['required', 'boolean'],
            'agent.max_active_tickets' => ['required', 'integer', 'min:1', 'max:1000'],
            'agent.category_ids' => ['present', 'array'],
            'agent.category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ];
    }

    public function toData(): UserData
    {
        return new UserData(
            name: $this->string('name')->trim()->value(),
            email: $this->string('email')->value(),
            role: $this->string('role')->value(),
            isActive: $this->boolean('is_active'),
            agent: new AgentProfileData(
                isAvailable: $this->boolean('agent.is_available'),
                maxActiveTickets: $this->integer('agent.max_active_tickets'),
                categoryIds: array_values(array_map('intval', (array) $this->input('agent.category_ids', []))),
            ),
        );
    }

    protected function uniqueEmail(): Unique
    {
        return Rule::unique('users', 'email');
    }
}
