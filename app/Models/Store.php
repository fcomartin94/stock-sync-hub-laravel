<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'platform',
        'base_url',
        'api_key',
        'api_secret',
        'last_synced_at',
    ];

    /**
     * Credentials must never be serialized into API responses.
     */
    protected $hidden = [
        'api_key',
        'api_secret',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'api_key' => 'encrypted',
        'api_secret' => 'encrypted',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}