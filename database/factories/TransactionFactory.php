<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $amount = fake()->numberBetween(100, 100000);

        return [
            'account_id'    => Account::factory(),
            'type'          => 'deposit',
            'amount'        => $amount,
            'balance_after' => $amount,
            'transfer_id'   => null,
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
