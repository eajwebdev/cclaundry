<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderLocationPing extends Model
{
    protected $fillable = [
        'rider_id', 'pickup_request_id',
        'latitude', 'longitude', 'accuracy', 'heading', 'speed', 'recorded_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'accuracy' => 'integer',
        'heading' => 'integer',
        'speed' => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function pickupRequest()
    {
        return $this->belongsTo(PickupRequest::class);
    }
}
