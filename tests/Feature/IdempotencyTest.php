<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rule 4: replaying a request with an Idempotency-Key that has already been
 * used must return the original result rather than applying the operation
 * twice, and a key reused with a different payload must be rejected as a
 * conflict.
 *
 * The first five tests prove the rule holds for the case it was designed for.
 * The next two cover BUG-04 and BUG-05, which these tests found and which are
 * now fixed: a key is fingerprinted by method, path and body rather than by
 * body alone. The last one still pins BUG-06, which is reported in TESTING.md
 * and not fixed.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function key(): string
    {
        return (string) Str::uuid();
    }

    // ------------------------------------------------- the rule as specified

    public function test_replaying_a_deposit_returns_the_original_result_and_applies_it_once(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $key = $this->key();

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000]);

        $first->assertStatus(201)->assertJsonPath('balance_after', 10000);

        $replay = $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000]);

        // Same status, and the ORIGINAL transaction - not a second one.
        $replay->assertStatus(201)
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('balance_after', 10000);

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_replaying_a_withdrawal_returns_the_original_result_and_applies_it_once(): void
    {
        $account = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($account);
        $key = $this->key();

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 4000]);

        $first->assertStatus(201)->assertJsonPath('balance_after', 6000);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 4000])
            ->assertStatus(201)
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('balance_after', 6000);

        $this->assertSame(6000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_replaying_a_transfer_returns_the_original_result_and_moves_the_money_once(): void
    {
        $from = Account::factory()->withBalance(10000)->create();
        $to = Account::factory()->create();
        Sanctum::actingAs($from);
        $key = $this->key();

        $body = [
            'from_account_id' => $from->id,
            'to_account_id'   => $to->id,
            'amount'          => 2500,
        ];

        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/transfers', $body);
        $first->assertStatus(201);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/transfers', $body)
            ->assertStatus(201)
            ->assertJsonPath('transfer_id', $first->json('transfer_id'))
            ->assertJsonPath('from.balance_after', 7500)
            ->assertJsonPath('to.balance_after', 2500);

        $this->assertSame(7500, $from->fresh()->balance);
        $this->assertSame(2500, $to->fresh()->balance);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected_as_a_conflict(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $key = $this->key();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
            ->assertStatus(201);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 5000])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_successful_money_operation_records_its_idempotency_key(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $key = $this->key();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
            ->assertStatus(201);

        $this->assertDatabaseHas('idempotency_keys', [
            'idempotency_key' => $key,
            'response_status' => 201,
        ]);
    }

    // ------------------------------------------------------ defects found

    /**
     * FIXED (TESTING.md, BUG-04). The fingerprint now covers the HTTP method
     * and the path, not just the body, so a key reused against a DIFFERENT
     * account is the 409 conflict rule 4 requires rather than a replay that
     * silently swallowed the deposit and answered 201.
     */
    public function test_the_same_key_on_a_different_account_is_rejected_as_a_conflict(): void
    {
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        $key = $this->key();

        Sanctum::actingAs($a);
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$a->id}/deposits", ['amount' => 10000])
            ->assertStatus(201);

        Sanctum::actingAs($b);
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$b->id}/deposits", ['amount' => 10000])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        // Neither account is silently short-changed: A keeps its deposit and
        // B is told to retry with a key of its own.
        $this->assertSame(10000, $a->fresh()->balance);
        $this->assertSame(0, $b->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    /**
     * FIXED (TESTING.md, BUG-05) - same fix as BUG-04. A withdrawal is no
     * longer mistaken for a replay of a deposit that happened to serialise to
     * the same bytes, because the path is part of the fingerprint.
     */
    public function test_the_same_key_on_a_different_endpoint_is_rejected_as_a_conflict(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $key = $this->key();

        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
            ->assertStatus(201);

        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 10000])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    /**
     * KNOWN BUG (TESTING.md, BUG-06).
     *
     * HandleIdempotency stores a row only for 2xx responses, so a key whose
     * first use failed is silently free again. Rule 4 says a key reused with a
     * different payload must be a conflict; here it is accepted and applied.
     *
     * The realistic damage: a client times out on a request that actually
     * returned 422, retries with an adjusted amount under the same key, and
     * moves money it believed had been rejected.
     */
    public function test_a_key_whose_first_use_failed_is_currently_reusable_with_a_different_payload(): void
    {
        $account = Account::factory()->create(); // balance 0
        Sanctum::actingAs($account);
        $key = $this->key();

        // First use fails: nothing to withdraw.
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 10000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_funds');

        // The key was never recorded, so it is free again.
        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => $key]);

        // Money arrives.
        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
            ->assertStatus(201);

        // Same key, different payload: rule 4 requires 409. It succeeds instead.
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 5000])
            ->assertStatus(201)
            ->assertJsonPath('type', 'withdrawal');

        $this->assertSame(5000, $account->fresh()->balance);
    }
}
