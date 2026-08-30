<?php

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountBalanceRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolls_up_a_child_account_into_its_parent(): void
    {
        $parent = Account::factory()->create(['allow_direct_posting' => false]);
        $child = Account::factory()->create(['parent_id' => $parent->id, 'allow_direct_posting' => true]);

        $rolled = AccountBalanceRollup::rollupToRoots([
            $child->id => ['debit' => 100.0, 'credit' => 0.0],
        ]);

        $this->assertSame(['debit' => 100.0, 'credit' => 0.0], $rolled[$parent->id]);
        $this->assertArrayNotHasKey($child->id, $rolled);
    }

    public function test_does_not_hang_on_a_parent_id_cycle(): void
    {
        $a = Account::factory()->create(['allow_direct_posting' => true]);
        $b = Account::factory()->create(['allow_direct_posting' => true]);

        // Force a data-corruption cycle (A's parent is B, B's parent is A) —
        // not reachable through normal app flows, but the rollup must not
        // hang forever if it ever happens.
        Account::withoutEvents(function () use ($a, $b): void {
            $a->parent_id = $b->id;
            $a->saveQuietly();
            $b->parent_id = $a->id;
            $b->saveQuietly();
        });

        $rolled = AccountBalanceRollup::rollupToRoots([
            $a->id => ['debit' => 50.0, 'credit' => 0.0],
        ]);

        $this->assertNotEmpty($rolled);
    }
}
