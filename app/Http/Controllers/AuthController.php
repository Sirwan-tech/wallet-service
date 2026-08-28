<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // POST /login
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login'    => ['required', 'string', 'max:254'], // email OR phone
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $login = trim($data['login']);

        // Find the account by email or phone
        $account = Account::where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        // Check account exists and password is correct
        if (!$account || !Hash::check($data['password'], $account->password)) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_credentials',
                    'message' => 'The provided credentials are incorrect.',
                ],
            ], 401);
        }

        // Reject frozen accounts
        if ($account->isFrozen()) {
            return response()->json([
                'error' => [
                    'code' => 'account_frozen',
                    'message' => 'This account is frozen.',
                ],
            ], 403);
        }

        // Issue a Sanctum token
        $token = $account->createToken('wallet-api', ['wallet:read', 'wallet:write'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'account' => [
                'id'         => $account->id,
                'owner_name' => $account->owner_name,
                'email'      => $account->email,
                'phone'      => $account->phone,
                'currency'   => $account->currency,
                'balance'    => $account->balance,
            ],
        ]);
    }

    // POST /logout  (requires token)
    public function logout(Request $request): JsonResponse
    {
        // Delete the current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    // GET /me  (requires token) — returns the logged-in account
    public function me(Request $request): JsonResponse
    {
        $account = $request->user();

        return response()->json([
            'id'         => $account->id,
            'owner_name' => $account->owner_name,
            'email'      => $account->email,
            'phone'      => $account->phone,
            'currency'   => $account->currency,
            'balance'    => $account->balance,
            'status'     => $account->status,
        ]);
    }
}
