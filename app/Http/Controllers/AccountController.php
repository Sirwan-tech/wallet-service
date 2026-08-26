<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    // POST /accounts

    public function store(Request $request): JsonResponse
    {
        // Normalize currency to uppercase before validating
        $request->merge([
            'currency' => strtoupper((string) $request->input('currency')),
        ]);

        $allowed = config('wallet.allowed_currencies');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:255'],
            'last_name'  => ['required', 'string', 'min:2', 'max:255'],
            'currency'   => ['required', 'string', 'size:3', 'in:' . implode(',', $allowed)],
        ], [
            'currency.in' => 'The currency must be one of: ' . implode(', ', $allowed) . '.',
        ]);

        $account = Account::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'currency'   => $data['currency'],
            'balance'    => 0,
            'status'     => 'active',
        ]);

        return response()->json($this->present($account), 201);
    }

    // GET /accounts/{id}
    public function show(string $id): JsonResponse
    {
        $account = Account::findOrFail($id);
        return response()->json($this->present($account));
    }

    // POST /accounts/{id}/deposits
    public function deposit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $tx = $this->wallet->deposit($id, $data['amount']);
        return response()->json($this->presentTx($tx), 201);
    }

    // POST /accounts/{id}/withdrawals
    public function withdraw(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $tx = $this->wallet->withdraw($id, $data['amount']);
        return response()->json($this->presentTx($tx), 201);
    }

    // GET /accounts/{id}/transactions
    public function transactions(Request $request, string $id): JsonResponse
    {
        $account = Account::findOrFail($id);

        $perPage = min((int) $request->query('per_page', 20), 100); // default 20, max 100

        $transactions = $account->transactions()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($transactions);
    }

    private function present(Account $account): array
    {
        return [
            'id'         => $account->id,
            'owner_name' => $account->owner_name,
            'first_name' => $account->first_name,
            'last_name'  => $account->last_name,
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
