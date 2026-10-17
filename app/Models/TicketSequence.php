<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Not a domain entity — exists only to serialise TicketNumberGenerator
 * under SELECT ... FOR UPDATE (ARCHITECTURE.md §4). No factory: it is a
 * counter row keyed by year, not a model with meaningful fake instances.
 */
#[Fillable(['year', 'last_number'])]
class TicketSequence extends Model
{
    protected $table = 'ticket_sequences';

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;
}
