<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobOrderItem extends Model
{
    protected $fillable = ['job_order_id', 'laundry_service_id', 'service_preset_id', 'description', 'service_category', 'quantity', 'unit_price', 'total', 'instructions'];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'total' => 'decimal:2'];

    /** Standard detergent and conditioner are included in the laundry service. */
    public function appearsOnReceipt(): bool
    {
        if ((float) $this->total !== 0.0 || ! in_array($this->service_category, [null, 'other', 'detergent', 'fabcon'], true)) {
            return true;
        }

        // Use the saved description so renaming or deleting a service does not
        // change an old receipt. Free add-ons still appear; price is not a filter.
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $this->description));

        return ! preg_match('/^(?:mrs\s*bloom|yen\s*yen)\b/', trim($normalized));
    }

    public function jobOrder() { return $this->belongsTo(JobOrder::class); }
    public function service() { return $this->belongsTo(LaundryService::class, 'laundry_service_id'); }
    public function preset() { return $this->belongsTo(ServicePreset::class, 'service_preset_id'); }
}
