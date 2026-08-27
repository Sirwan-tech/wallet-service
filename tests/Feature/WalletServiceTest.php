<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = app(WalletService::class);
    }

    public function test_deposit_credits_the_account_and_records_a_transaction(): void
    {
        $account = Account::factory()->create();

        $tx = $this->wallet->deposit($account->id, 10000);

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertSame('deposit', $tx->type);
        $this->assertSame(10000, $tx->amount);
        $this->assertSame(10000, $tx->balance_after);
        $this->assertSame(1, Transaction::where('account_id', $account->id)->count());
    }

    public function test_withdrawal_debits_the_account(): void
    {
        $account = Account::factory()->withBalance(10000)->create();

        $tx = $this->wallet->withdraw($account->id, 4000);

        $this->assertSame(6000, $account->fresh()->balance);
        $this->assertSame('withdrawal', $tx->type);
        $this->assertSame(4000, $tx->amount);
        $this->assertSame(6000, $tx->balance_after);
    }

    public function test_withdrawing_the_entire_balance_is_allowed_and_leaves_zero(): void
    {
        $account = Account::factory()->withBalance(10000)->create();

        $tx = $this->wallet->withdraw($account->id, 10000);

        $this->assertSame(0, $account->fresh()->balance);
        $this->assertSame(0, $tx->balance_after);
    }

    public function test_withdrawing_one_more_than_the_balance_is_rejected_and_changes_nothing(): void
    {
        $account = Account::factory()->withBalance(10000)->create();

        try {
            $this->wallet->withdraw($account->id, 10001);
            $this->fail('Expected InsufficientFundsException was not thrown.');
        } catch (InsufficientFundsException) {
            // expected
        }

        $this->assertSame(10000, $account->fresh()->balance);
        $this->assertSame(0, Transaction::where('account_id', $account->id)->count());
    }
}
