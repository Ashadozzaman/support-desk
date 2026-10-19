<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** One question to an AI assistant — the help chat and the staff data assistant. */
class AskSupportChatRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:'.(int) config('ai.chat.max_message_length')],
        ];
    }

    public function question(): string
    {
        return $this->string('message')->trim()->value();
    }
}
