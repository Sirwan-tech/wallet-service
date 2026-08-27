<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name'  => fake()->lastName(),
            'email'      => fake()->unique()->safeEmail(),
            'phone'      => '+9647' . fake()->unique()->numerify('########'),
            'password'   => 'password',
            'currency'   => 'USD',
            'balance'    => 0,
            'status'     => 'active',
        ];
    }

    /** An account that already holds this balance, in minor units (cents). */
    public function withBalance(int $minorUnits): static
    {
        return $this->state(fn () => ['balance' => $minorUnits]);
    }

    public function currency(string $code): static
    {
        return $this->state(fn () => ['currency' => strtoupper($code)]);
    }

    public function frozen(): static
    {
        return $this->state(fn () => ['status' => 'frozen']);
    }
}
