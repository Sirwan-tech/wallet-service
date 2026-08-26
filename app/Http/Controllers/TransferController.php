<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private WalletService $wallet) {}

    // POST /transfers
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'string'],
            'to_account_id'   => ['required', 'string', 'different:from_account_id'],
            'amount'          => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->wallet->transfer(
            $data['from_account_id'],
            $data['to_account_id'],
            $data['amount']
        );

        return response()->json([
            'transfer_id' => $result['transfer_id'],
            'from' => [
                'account_id'    => $result['out']->account_id,
                'amount'        => $result['out']->amount,
                'balance_after' => $result['out']->balance_after,
            ],
            'to' => [
                'account_id'    => $result['in']->account_id,
                'amount'        => $result['in']->amount,
                'balance_after' => $result['in']->balance_after,
            ],
        ], 201);
    }
}
