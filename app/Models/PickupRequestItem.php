<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One service on a booking: what was chosen, how much of it, and what it was
 * priced at on the day. A snapshot on purpose — renaming or repricing a service
 * later must not rewrite what a customer already agreed to.
 */
class PickupRequestItem extends Model
{
    protected $fillable = [
        'pickup_request_id', 'laundry_service_id', 'service_preset_id',
        'service_name', 'service_price', 'pricing_type',
        'quantity', 'unit', 'billable_quantity', 'line_total',
        'is_addon', 'sort_order',
    ];

    protected $casts = [
        'service_price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'billable_quantity' => 'decimal:2',
        'line_total' => 'decimal:2',
        'is_addon' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function pickupRequest() { return $this->belongsTo(PickupRequest::class); }
    public function service() { return $this->belongsTo(LaundryService::class, 'laundry_service_id'); }
    public function preset() { return $this->belongsTo(ServicePreset::class, 'service_preset_id'); }

    /** Whether this line is measured on the scale, and so checkable at the counter. */
    public function isWeighed(): bool
    {
        return $this->unit === 'kg';
    }

    /** "8 kg", "2 pairs", "3x" — the amount as the customer gave it. */
    public function quantityLabel(): string
    {
        $amount = rtrim(rtrim(number_format((float) $this->quantity, 2), '0'), '.');

        return match ($this->unit) {
            'kg' => $amount.' kg',
            'pc' => $amount.' '.($amount === '1' ? 'pair' : 'pairs'),
            'load' => $amount.' '.($amount === '1' ? 'load' : 'loads'),
            default => $amount.'x',
        };
    }

    /** The same line for the counter: "Regular Laundry · 8 kg". */
    public function label(): string
    {
        return $this->service_name.' · '.$this->quantityLabel();
    }
}
