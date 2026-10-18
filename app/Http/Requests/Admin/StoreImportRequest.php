<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    /**
     * Authorization is checked explicitly in the controller
     * (CODING_STRUCTURE.md §3) so it is visible in one place.
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('support.import.max_kb')],
        ];
    }
}
