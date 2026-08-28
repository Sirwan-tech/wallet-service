<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sirwan',
            'last_name'  => 'Ahmed',
            'email'      => 'sirwan@example.com',
            'phone'      => '+9647701234567',
            'password'   => 'secret123',
            'currency'   => 'USD',
        ], $overrides);
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    // ---------------------------------------------------------------- create

    public function test_it_creates_an_account_with_a_zero_balance(): void
    {
        $response = $this->postJson('/api/accounts', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('owner_name', 'Sirwan Ahmed')
            ->assertJsonPath('status', 'active');

        $this->assertTrue(Str::isUuid($response->json('id')));
        $this->assertDatabaseHas('accounts', ['email' => 'sirwan@example.com', 'balance' => 0]);
    }

    public function test_it_never_exposes_the_password(): void
    {
        $response = $this->postJson('/api/accounts', $this->payload());

        $response->assertStatus(201);
        $this->assertArrayNotHasKey('password', $response->json());
    }

    public function test_it_normalises_a_lowercase_currency_code(): void
    {
        $this->postJson('/api/accounts', $this->payload(['currency' => 'eur']))
            ->assertStatus(201)
            ->assertJsonPath('currency', 'EUR');
    }

    public function test_it_rejects_an_unsupported_currency(): void
    {
        $this->postJson('/api/accounts', $this->payload(['currency' => 'JPY']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        Account::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/accounts', $this->payload(['email' => 'taken@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    // ------------------------------------------------------------------ read

    public function test_reading_an_account_requires_authentication(): void
    {
        $account = Account::factory()->create();

        $this->getJson("/api/accounts/{$account->id}")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_it_returns_an_account_with_its_current_balance(): void
    {
        $account = Account::factory()->withBalance(2500)->create();
        Sanctum::actingAs($account);

        $this->getJson("/api/accounts/{$account->id}")
            ->assertStatus(200)
            ->assertJsonPath('id', $account->id)
            ->assertJsonPath('balance', 2500)
            ->assertJsonPath('currency', 'USD');
    }

    /**
     * KNOWN GAP (TESTING.md, BUG-02): the ModelNotFoundException renderer in
     * bootstrap/app.php never fires, because Laravel converts that exception
     * into a NotFoundHttpException before render callbacks are consulted. The
     * status code is honest; the body escapes the error envelope. Pinned here
     * so that fixing it is a deliberate, visible change.
     */
    public function test_an_unknown_account_returns_404_but_not_the_error_envelope(): void
    {
        Sanctum::actingAs(Account::factory()->create());

        $response = $this->getJson('/api/accounts/' . Str::uuid());

        $response->assertStatus(404);
        $this->assertNull($response->json('error.code'));
    }

    // --------------------------------------------------------------- deposit

    public function test_it_deposits_into_an_account(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 10000])
            ->assertStatus(201)
            ->assertJsonPath('account_id', $account->id)
            ->assertJsonPath('type', 'deposit')
            ->assertJsonPath('amount', 10000)
            ->assertJsonPath('balance_after', 10000);

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 1);
    }

    // -------------------------------------------------------------- withdraw

    public function test_it_withdraws_from_an_account(): void
    {
        $account = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($account);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 4000])
            ->assertStatus(201)
            ->assertJsonPath('type', 'withdrawal')
            ->assertJsonPath('amount', 4000)
            ->assertJsonPath('balance_after', 6000);

        $this->assertSame(6000, $account->fresh()->balance);
    }

    public function test_it_allows_withdrawing_the_entire_balance(): void
    {
        $account = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($account);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 10000])
            ->assertStatus(201)
            ->assertJsonPath('balance_after', 0);

        $this->assertSame(0, $account->fresh()->balance);
    }

    public function test_it_rejects_a_withdrawal_that_would_overdraw_and_records_nothing(): void
    {
        $account = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($account);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/withdrawals", ['amount' => 10001])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_funds');

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    // ------------------------------------------------------------ validation

    public function test_a_money_endpoint_requires_an_idempotency_key(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        $this->postJson("/api/accounts/{$account->id}/deposits", ['amount' => 100])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[DataProvider('invalidAmounts')]
    public function test_it_rejects_an_invalid_amount(mixed $amount): void
    {
        $account = Account::factory()->withBalance(10000)->create();
        Sanctum::actingAs($account);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$account->id}/deposits", ['amount' => $amount])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public static function invalidAmounts(): array
    {
        return [
            'zero'        => [0],
            'negative'    => [-100],
            'fractional'  => [10.5],
            'non numeric' => ['abc'],
            'null'        => [null],
        ];
    }

    // --------------------------------------------------------------- history

    private function threeDepositsOneMinuteApart(Account $account): void
    {
        $wallet = app(WalletService::class);

        $this->travelTo(now()->subMinutes(3));
        $wallet->deposit($account->id, 100);
        $this->travelTo(now()->addMinute());
        $wallet->deposit($account->id, 200);
        $this->travelTo(now()->addMinute());
        $wallet->deposit($account->id, 300);
        $this->travelBack();
    }

    public function test_transaction_history_is_paginated_and_newest_first(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $this->threeDepositsOneMinuteApart($account);

        $this->getJson("/api/accounts/{$account->id}/transactions")
            ->assertStatus(200)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 20)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount', 300)
            ->assertJsonPath('data.1.amount', 200)
            ->assertJsonPath('data.2.amount', 100);
    }

    public function test_transaction_history_honours_an_explicit_page_size(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $this->threeDepositsOneMinuteApart($account);

        $this->getJson("/api/accounts/{$account->id}/transactions?per_page=2")
            ->assertStatus(200)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.amount', 300);

        $this->getJson("/api/accounts/{$account->id}/transactions?per_page=2&page=2")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', 100);
    }

    public function test_transaction_history_caps_the_page_size(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);

        $this->getJson("/api/accounts/{$account->id}/transactions?per_page=5000")
            ->assertStatus(200)
            ->assertJsonPath('per_page', 100);
    }

    /**
     * FIXED (TESTING.md, BUG-03). per_page is now clamped. An invalid value
     * falls back to the configured default instead of reaching paginate() as a
     * negative number, where Laravel discards the negative LIMIT but still
     * emits offset(0) - a bare SQL OFFSET that MySQL rejects with error 1064,
     * so the endpoint used to answer 500.
     */
    public function test_an_invalid_page_size_falls_back_to_the_configured_default(): void
    {
        $account = Account::factory()->create();
        Sanctum::actingAs($account);
        $this->threeDepositsOneMinuteApart($account);

        foreach (['-1', '0', 'abc'] as $value) {
            $this->getJson("/api/accounts/{$account->id}/transactions?per_page={$value}")
                ->assertStatus(200)
                ->assertJsonPath('per_page', 20)
                ->assertJsonCount(3, 'data');
        }
    }

    // ------------------------------------------------------- authorization

    /**
     * A bearer token proves who the caller is; on its own it authorises
     * nothing. These three cover BUG-07, which was found by hand and is now
     * fixed - before the fix every one of them returned 200 or 201.
     */
    public function test_a_caller_cannot_read_another_accounts_details(): void
    {
        $alice = Account::factory()->create();
        $bob = Account::factory()->withBalance(5000)->create();

        Sanctum::actingAs($alice);

        $this->getJson("/api/accounts/{$bob->id}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_caller_cannot_withdraw_from_another_account(): void
    {
        $alice = Account::factory()->create();
        $bob = Account::factory()->withBalance(5000)->create();

        Sanctum::actingAs($alice);

        $this->withHeader('Idempotency-Key', $this->key())
            ->postJson("/api/accounts/{$bob->id}/withdrawals", ['amount' => 100])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertSame(5000, $bob->fresh()->balance);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_caller_cannot_read_another_accounts_history(): void
    {
        $alice = Account::factory()->create();
        $bob = Account::factory()->create();

        Sanctum::actingAs($alice);

        $this->getJson("/api/accounts/{$bob->id}/transactions")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }
}
