<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AssignTicketRequest extends FormRequest
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
            'agent_id' => [
                'required',
                'integer',
                'exists:users,id',
                function (string $attribute, mixed $value, callable $fail): void {
                    $agent = User::query()->with('agentProfile')->find($value);

                    if ($agent === null || ! $agent->is_active || $agent->agentProfile === null
                        || ! $agent->can(Permission::TicketsClaim->value)) {
                        $fail('The selected agent is not available for assignment.');
                    }
                },
            ],
        ];
    }

    public function agentId(): int
    {
        return $this->integer('agent_id');
    }
}
