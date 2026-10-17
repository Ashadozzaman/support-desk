<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
