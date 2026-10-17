<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TicketStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTicketStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(TicketStatus::class)],
        ];
    }

    public function status(): TicketStatus
    {
        return TicketStatus::from($this->string('status')->value());
    }
}
