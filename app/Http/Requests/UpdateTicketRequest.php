<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\UpdateTicketData;
use App\Enums\TicketPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTicketRequest extends FormRequest
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
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }

    public function toData(): UpdateTicketData
    {
        return new UpdateTicketData(
            subject: $this->string('subject')->value(),
            description: $this->string('description')->value(),
            priority: TicketPriority::from($this->string('priority')->value()),
            categoryId: $this->integer('category_id') ?: null,
        );
    }
}
