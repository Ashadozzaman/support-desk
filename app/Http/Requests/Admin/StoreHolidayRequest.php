<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    public function holidayDate(): string
    {
        return $this->string('date')->value();
    }

    public function holidayName(): string
    {
        return $this->string('name')->value();
    }
}
