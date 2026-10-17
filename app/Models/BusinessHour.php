<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BusinessHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['day_of_week', 'is_working_day', 'start_time', 'end_time'])]
class BusinessHour extends Model
{
    /** @use HasFactory<BusinessHourFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_working_day' => 'boolean',
        ];
    }
}
