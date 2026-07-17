<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'quantity',
        'recorded_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}