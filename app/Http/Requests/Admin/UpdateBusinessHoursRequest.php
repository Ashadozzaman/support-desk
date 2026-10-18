<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessHoursRequest extends FormRequest
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
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.is_working_day' => ['required', 'boolean'],
            'hours.*.start_time' => ['required_if:hours.*.is_working_day,1', 'nullable', 'date_format:H:i'],
            'hours.*.end_time' => ['required_if:hours.*.is_working_day,1', 'nullable', 'date_format:H:i', 'after:hours.*.start_time'],
        ];
    }

    /**
     * @return array<int, array{is_working_day: bool, start_time: ?string, end_time: ?string}>
     */
    public function toData(): array
    {
        $rows = [];

        foreach ($this->validated('hours') as $row) {
            $isWorkingDay = (bool) $row['is_working_day'];

            $rows[(int) $row['day_of_week']] = [
                'is_working_day' => $isWorkingDay,
                'start_time' => $isWorkingDay ? $row['start_time'].':00' : null,
                'end_time' => $isWorkingDay ? $row['end_time'].':00' : null,
            ];
        }

        return $rows;
    }
}
