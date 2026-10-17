<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_ticket_number_is_rejected_by_the_database()
    {
        Ticket::factory()->create(['ticket_number' => 'TKT-2026-000001']);

        $this->expectException(QueryException::class);

        Ticket::factory()->create(['ticket_number' => 'TKT-2026-000001']);
    }

    public function test_ticket_external_ref_allows_multiple_nulls_but_rejects_duplicates()
    {
        Ticket::factory()->create(['external_ref' => null]);
        Ticket::factory()->create(['external_ref' => null]);

        Ticket::factory()->create(['external_ref' => 'IMPORT-1']);

        $this->expectException(QueryException::class);

        Ticket::factory()->create(['external_ref' => 'IMPORT-1']);
    }

    public function test_duplicate_category_slug_is_rejected_by_the_database()
    {
        Category::factory()->create(['slug' => 'billing']);

        $this->expectException(QueryException::class);

        Category::factory()->create(['slug' => 'billing']);
    }

    public function test_duplicate_sla_policy_priority_is_rejected_by_the_database()
    {
        SlaPolicy::factory()->create(['priority' => 'critical']);

        $this->expectException(QueryException::class);

        SlaPolicy::factory()->create(['priority' => 'critical']);
    }

    public function test_ticket_cannot_reference_a_missing_customer()
    {
        $this->expectException(QueryException::class);

        Ticket::factory()->create(['created_by' => 999_999]);
    }

    public function test_deleting_a_ticket_cascades_to_its_attachments()
    {
        $ticket = Ticket::factory()->create();
        $attachment = Attachment::factory()->create(['ticket_id' => $ticket->id]);

        $ticket->delete();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
    }

    public function test_deleting_a_category_sets_ticket_category_to_null()
    {
        $category = Category::factory()->create();
        $ticket = Ticket::factory()->create(['category_id' => $category->id]);

        $category->delete();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'category_id' => null]);
    }
}
