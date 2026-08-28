<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
        use HasUuids, HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    // Records are immutable: created_at only, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'account_id',
        'type',
        'amount',
        'balance_after',
        'transfer_id',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    protected static function booted(): void
    {
        // The application treats this as an append-only ledger. Database
        // privileges should enforce the same rule in a production deployment.
        static::updating(fn () => throw new LogicException('Transaction records are immutable.'));
        static::deleting(fn () => throw new LogicException('Transaction records are immutable.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
