<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTOs\RoleData;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Create and edit share one request; on edit the unique name ignores the role itself. */
class RoleRequest extends FormRequest
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
        /** @var Role|null $role */
        $role = $this->route('role');

        return [
            // Lowercase slug: role names are identifiers (spatie looks them up
            // by name), the UI shows Str::headline() of it.
            'name' => [
                'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9-]*$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(Permission::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.regex' => 'Use lowercase letters, digits and dashes, starting with a letter (e.g. senior-agent).'];
    }

    public function toData(): RoleData
    {
        return new RoleData(
            name: $this->string('name')->trim()->value(),
            permissions: array_values(array_map('strval', (array) $this->input('permissions', []))),
        );
    }
}
