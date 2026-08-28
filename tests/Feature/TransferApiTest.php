<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    private function transfer(array $body)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/transfers', $body);
    }

    public function test_a_transfer_requires_authentication(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->create();

        $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 100,
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->assertSame(10000, $from->fresh()->balance);
    }

    public function test_it_moves_money_between_two_accounts_of_the_same_currency(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->create();
        Sanctum::actingAs($from);

        $response = $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 2500,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('from.account_id', $from->id)
            ->assertJsonPath('from.amount', 2500)
            ->assertJsonPath('from.balance_after', 7500)
            ->assertJsonPath('to.account_id', $to->id)
            ->assertJsonPath('to.amount', 2500)
            ->assertJsonPath('to.balance_after', 2500);

        $this->assertSame(7500, $from->fresh()->balance);
        $this->assertSame(2500, $to->fresh()->balance);

        // Both legs are recorded and share one transfer_id.
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame(2, Transaction::where('transfer_id', $response->json('transfer_id'))->count());
        $this->assertDatabaseHas('transactions', ['account_id' => $from->id, 'type' => 'transfer_out']);
        $this->assertDatabaseHas('transactions', ['account_id' => $to->id, 'type' => 'transfer_in']);
    }

    public function test_money_is_conserved_across_a_transfer(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->withBalance(4000)->create();
        Sanctum::actingAs($from);

        $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 3000,
        ])->assertStatus(201);

        $this->assertSame(14000, $from->fresh()->balance + $to->fresh()->balance);
    }

    public function test_it_rejects_a_transfer_between_different_currencies_and_records_neither_leg(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->currency('EUR')->create();
        Sanctum::actingAs($from);

        $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 2500,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'currency_mismatch');

        $this->assertSame(10000, $from->fresh()->balance);
        $this->assertSame(0, $to->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * Rule 2: a transfer is atomic. When the debit leg is rejected the credit
     * leg must not exist either - no partial transfer, no orphan record.
     */
    public function test_an_overdrawing_transfer_records_neither_leg(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->create();
        Sanctum::actingAs($from);

        $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 10001,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_funds');

        $this->assertSame(10000, $from->fresh()->balance);
        $this->assertSame(0, $to->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_it_allows_transferring_the_entire_balance(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->create();
        Sanctum::actingAs($from);

        $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 10000,
        ])->assertStatus(201);

        $this->assertSame(0, $from->fresh()->balance);
        $this->assertSame(10000, $to->fresh()->balance);
    }

    public function test_it_rejects_a_transfer_to_the_same_account(): void
    {
        $account = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($account);

        $this->transfer([
            'from_account_id' => $account->id,
            'to_account_id'   => $account->id,
            'amount'          => 100,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_transfer_to_an_unknown_account_changes_nothing(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($from);

        $this->transfer([
            'from_account_id' => $from->id,
            'to_account_id'   => (string) Str::uuid(),
            'amount'          => 100,
        ])->assertStatus(404);

        $this->assertSame(10000, $from->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_transfer_requires_an_idempotency_key(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->create();
        Sanctum::actingAs($from);

        $this->postJson('/api/transfers', [
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 100,
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertDatabaseCount('transactions', 0);
    }

    /**
     * BUG-07, transfer leg. from_account_id arrives in the request body, so
     * without an ownership check any token could drain any account.
     */
    public function test_a_caller_cannot_transfer_out_of_another_account(): void
    {
        $alice = Account::factory()->create();
        $bob = Account::factory()->withBalance(10000)->create();

        Sanctum::actingAs($alice);

        $this->transfer([
            'from_account_id' => $bob->id,
            'to_account_id'   => $alice->id,
            'amount'          => 5000,
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame(10000, $bob->fresh()->balance);
        $this->assertSame(0, $alice->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }
}
