<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\CreateReplyData;
use App\Enums\ReplyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReplyRequest extends FormRequest
{
    /**
     * Authorization is checked explicitly in the controller, since which
     * ability applies depends on the requested reply type.
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
            'body' => ['required', 'string'],
            'type' => ['nullable', Rule::enum(ReplyType::class)],
            'attachments' => ['nullable', 'array', 'max:'.config('support.uploads.max_files')],
            'attachments.*' => [
                'file',
                'max:'.config('support.uploads.max_kb'),
                'mimes:'.implode(',', config('support.uploads.mimes')),
            ],
        ];
    }

    /**
     * Defaults to a public reply; the controller still authorizes against
     * this value before either service method runs.
     */
    public function replyType(): ReplyType
    {
        return $this->filled('type') ? ReplyType::from($this->string('type')->value()) : ReplyType::Reply;
    }

    public function toData(): CreateReplyData
    {
        return new CreateReplyData(
            body: $this->string('body')->value(),
            attachments: $this->file('attachments') ?? [],
        );
    }
}
