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
 * The last three cover BUG-04, BUG-05 and BUG-06 — all three were found by
 * these tests, and all three are now fixed. A key is fingerprinted by caller,
 * method, path and body rather than by body alone, and every terminal outcome
 * below 500 is recorded rather than only the 2xx ones.
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

    /**
     * The header reaches the database and the logs, so its shape is bounded:
     * 8 to 255 characters from a safe ASCII set, starting alphanumeric. A UUID
     * satisfies it. A malformed key is refused before the operation runs, so a
     * bad header can never move money unrecorded.
     */
    public function test_a_malformed_idempotency_key_is_refused_before_anything_happens(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        foreach (['short', 'has spaces in it', '-starts-with-a-dash', str_repeat('a', 256)] as $badKey) {
            $this->withHeader('Idempotency-Key', $badKey)
                ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'idempotency_key_invalid');
        }

        $this->assertSame(0, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
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
     * FIXED (TESTING.md, BUG-06). The middleware used to record a row only for
     * 2xx responses, so a key whose first use failed was silently free again
     * and could come back with a different payload. Every terminal outcome
     * below 500 is now recorded, so a failure replays as the original failure
     * and a changed payload is the conflict rule 4 requires. Server errors are
     * still deliberately not cached, so a 5xx stays retryable.
     */
    public function test_a_key_whose_first_use_failed_is_recorded_and_cannot_be_reused(): void
    {
        $account = Account::factory()->create(); // balance 0
        Sanctum::actingAs($account);
        $key = $this->key();

        // First use fails: there is nothing to withdraw.
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 10000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_funds');

        // The failure is a terminal outcome, so it is recorded ...
        $this->assertDatabaseHas('idempotency_keys', [
            'idempotency_key' => $key,
            'response_status' => 422,
        ]);

        // ... and replaying the identical request returns that original result.
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 10000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_funds');

        // Money arrives under a different key.
        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
            ->assertStatus(201);

        // The used key with a changed payload is now a conflict, not a success.
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 5000])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(10000, $account->fresh()->balance);
    }
}
