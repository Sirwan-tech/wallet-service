<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'currency',
        'balance',
        'status',
    ];

    protected $casts = [
        'balance' => 'integer',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // Friendly full name for API responses
    public function getOwnerNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }
}
