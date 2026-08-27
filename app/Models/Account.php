<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Account extends Authenticatable
{
    use HasUuids, HasApiTokens;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'currency',
        'balance',
        'status',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'balance' => 'integer',
        'password' => 'hashed',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function getOwnerNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }
}
