<?php

namespace App\Http\Controllers;

use App\Exceptions\NotAccountOwnerException;
use App\Models\Account;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    // POST /accounts
    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'currency' => strtoupper((string) $request->input('currency')),
        ]);

        $allowed = config('wallet.allowed_currencies');

        // Names are plain text, never markup. The Unicode-aware rule keeps
        // Kurdish and other international names valid while rejecting HTML.
        $nameRules = ['required', 'string', 'min:2', 'max:100', "regex:/^[\\p{L}\\p{M}\\s'-]+$/u"];

        $data = $request->validate([
            'first_name' => $nameRules,
            'last_name'  => $nameRules,
            'email'      => ['required', 'string', 'email:rfc', 'max:254', 'unique:accounts,email'],
            'phone'      => ['required', 'string', 'regex:/^\\+[1-9][0-9]{7,14}$/', 'unique:accounts,phone'],
            'password'   => [
                'required',
                'string',
                'max:1024',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'currency'   => ['required', 'string', 'size:3', 'in:' . implode(',', $allowed)],
        ], [
            'currency.in' => 'The currency must be one of: ' . implode(', ', $allowed) . '.',
            'first_name.regex' => 'The first name may contain only letters, spaces, apostrophes, and hyphens.',
            'last_name.regex' => 'The last name may contain only letters, spaces, apostrophes, and hyphens.',
            'phone.regex' => 'The phone number must be in E.164 format.',
        ]);

        $account = Account::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'phone'      => $data['phone'],
            'password'   => $data['password'],
            'currency'   => $data['currency'],
            'balance'    => 0,
            'status'     => 'active',
        ]);

        return response()->json($this->present($account), 201);
    }

    // GET /accounts/{id}
    public function show(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $this->assertOwns($request, $account->id);

        return response()->json($this->present($account));
    }

    // POST /accounts/{id}/deposits
    public function deposit(Request $request, string $id): JsonResponse
    {
        $this->assertOwns($request, $id);

        $data = $request->validate([
            'amount' => ['bail', 'required', 'integer', 'min:1', 'max:' . config('wallet.max_amount_minor')],
        ]);

        $tx = $this->wallet->deposit($id, $data['amount']);

        return response()->json($this->presentTx($tx), 201);
    }

    // POST /accounts/{id}/withdrawals
    public function withdraw(Request $request, string $id): JsonResponse
    {
        $this->assertOwns($request, $id);

        $data = $request->validate([
            'amount' => ['bail', 'required', 'integer', 'min:1', 'max:' . config('wallet.max_amount_minor')],
        ]);

        $tx = $this->wallet->withdraw($id, $data['amount']);

        return response()->json($this->presentTx($tx), 201);
    }

    // GET /accounts/{id}/transactions
    public function transactions(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $this->assertOwns($request, $account->id);

        // Page size: fall back to the configured default for a missing, zero,
        // negative or non-numeric value, then clamp to the configured maximum.
        // Without the lower bound a negative value produced a bare SQL OFFSET
        // with no LIMIT, which MySQL rejects (TESTING.md, BUG-03).
        $default = (int) config('wallet.pagination.default_per_page');
        $max = (int) config('wallet.pagination.max_per_page');

        $perPage = (int) $request->query('per_page', $default);
        $perPage = $perPage > 0 ? min($perPage, $max) : $default;

        $transactions = $account->transactions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')   // stable tiebreaker: ids are ordered UUIDs (TESTING.md, BUG-11)
            ->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * A bearer token proves who the caller is. It does not authorise them to
     * act on somebody else's account (TESTING.md, BUG-07).
     */
    private function assertOwns(Request $request, string $id): void
    {
        if ($request->user()->getKey() !== $id) {
            throw new NotAccountOwnerException();
        }
    }

    private function present(Account $account): array
    {
        return [
            'id'         => $account->id,
            'owner_name' => $account->owner_name,
            'first_name' => $account->first_name,
            'last_name'  => $account->last_name,
            'email'      => $account->email,
            'phone'      => $account->phone,
            'currency'   => $account->currency,
            'balance'    => $account->balance,
            'status'     => $account->status,
            'created_at' => $account->created_at,
        ];
    }

    private function presentTx($tx): array
    {
        return [
            'id'            => $tx->id,
            'account_id'    => $tx->account_id,
            'type'          => $tx->type,
            'amount'        => $tx->amount,
            'balance_after' => $tx->balance_after,
            'transfer_id'   => $tx->transfer_id,
            'created_at'    => $tx->created_at,
        ];
    }
}
