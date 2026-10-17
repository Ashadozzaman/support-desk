<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\AgentProfile;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent (updateOrCreate by email + assignRole, which attaches only
 * missing pivots) so re-running db:seed never duplicates demo accounts.
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@support-desk.test'],
            ['name' => 'Ada Admin', 'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true],
        );
        $admin->assignRole(RoleEnum::Admin->value);

        $categories = Category::query()->pluck('id');

        $agents = [
            ['name' => 'Alex Agent', 'email' => 'agent1@support-desk.test'],
            ['name' => 'Bailey Agent', 'email' => 'agent2@support-desk.test'],
            ['name' => 'Casey Agent', 'email' => 'agent3@support-desk.test'],
        ];

        foreach ($agents as $i => $data) {
            $agent = User::query()->updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true],
            );
            $agent->assignRole(RoleEnum::Agent->value);

            AgentProfile::query()->updateOrCreate(
                ['user_id' => $agent->id],
                ['is_available' => true, 'max_active_tickets' => 10],
            );

            // Spread category skills across agents so auto-assign candidate pools differ.
            $skills = $categories->filter(fn (int $id, int $index) => $index % 3 === $i)->values();
            $agent->categories()->sync($skills);
        }

        foreach (range(1, 5) as $n) {
            $customer = User::query()->updateOrCreate(
                ['email' => "customer{$n}@support-desk.test"],
                ['name' => "Customer {$n}", 'password' => Hash::make('password'), 'email_verified_at' => now(), 'is_active' => true],
            );
            $customer->assignRole(RoleEnum::Customer->value);
        }
    }
}
