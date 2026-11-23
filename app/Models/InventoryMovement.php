<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = ['inventory_id', 'user_id', 'movement_type', 'quantity', 'unit_cost', 'change_qty', 'reason', 'remarks'];

    public const REASONS = ['count', 'spill', 'cancelled_order', 'other'];

    protected $casts = ['quantity' => 'decimal:4'];

    public function inventory() { return $this->belongsTo(Inventory::class); }
    public function user() { return $this->belongsTo(User::class); }
}
