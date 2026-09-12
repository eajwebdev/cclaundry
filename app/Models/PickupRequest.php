<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PickupRequest extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'picked_up', 'completed', 'cancelled'];

    public const PICKUP_SLOTS = [
        'morning' => '8:00 AM - 11:00 AM',
        'afternoon' => '1:00 PM - 4:00 PM',
        'evening' => '4:00 PM - 7:00 PM',
    ];

    protected $fillable = [
        'reference_no', 'tag_code', 'customer_id', 'branch_id', 'laundry_service_id', 'service_preset_id',
        'service_name', 'service_price', 'service_pricing_type', 'estimated_kilos',
        'contact_name', 'contact_phone', 'contact_email',
        'pickup_address', 'pickup_landmark', 'pickup_date', 'pickup_slot',
        'pickup_latitude', 'pickup_longitude',
        'delivery_preference', 'delivery_address', 'delivery_date', 'delivery_slot',
        'delivery_latitude', 'delivery_longitude',
        'is_rush', 'notes', 'estimated_total',
        'collected_amount', 'collected_payment_method',
        'status', 'job_order_id', 'handled_by',
        'rider_id', 'assigned_at', 'picked_up_at', 'delivered_at',
        'confirmed_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'estimated_kilos' => 'decimal:2',
        'service_price' => 'decimal:2',
        'estimated_total' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'is_rush' => 'boolean',
        'pickup_date' => 'date',
        'delivery_date' => 'date',
        'pickup_latitude' => 'decimal:7',
        'pickup_longitude' => 'decimal:7',
        'delivery_latitude' => 'decimal:7',
        'delivery_longitude' => 'decimal:7',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function service() { return $this->belongsTo(LaundryService::class, 'laundry_service_id'); }
    public function preset() { return $this->belongsTo(ServicePreset::class, 'service_preset_id'); }
    public function jobOrder() { return $this->belongsTo(JobOrder::class); }
    public function handler() { return $this->belongsTo(User::class, 'handled_by'); }
    public function rider() { return $this->belongsTo(User::class, 'rider_id'); }
    public function locationPings() { return $this->hasMany(RiderLocationPing::class); }

    /** Whether a rider can be sent to this booking at all. */
    public function isAssignable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    /**
     * The leg the rider is currently driving: out to the customer to collect,
     * or back to them with the finished laundry.
     */
    public function activeLeg(): ?string
    {
        return match ($this->status) {
            'pending', 'confirmed' => 'pickup',
            'picked_up' => $this->wantsDelivery() ? 'delivery' : null,
            default => null,
        };
    }

    /** Where the rider is headed right now, as [lat, lng], if it is known. */
    public function destinationCoordinates(): ?array
    {
        $leg = $this->activeLeg();

        if ($leg === 'pickup' && $this->pickup_latitude !== null) {
            return [(float) $this->pickup_latitude, (float) $this->pickup_longitude];
        }

        if ($leg === 'delivery') {
            if ($this->delivery_latitude !== null) {
                return [(float) $this->delivery_latitude, (float) $this->delivery_longitude];
            }

            // Delivery defaults to the pickup address when none was given.
            if ($this->pickup_latitude !== null) {
                return [(float) $this->pickup_latitude, (float) $this->pickup_longitude];
            }
        }

        return null;
    }

    /** Is this booking at a stage where a live rider map is worth showing? */
    public function isTrackable(): bool
    {
        return $this->rider_id !== null
            && in_array($this->status, ['confirmed', 'picked_up'], true);
    }

    public function serviceTypeLabel(): string
    {
        return $this->service_name
            ?: ($this->preset?->name ?: $this->service?->name ?: 'Laundry service');
    }

    /** How the snapshotted price reads, e.g. "per load". */
    public function servicePriceUnitLabel(): string
    {
        return match ($this->service_pricing_type) {
            'preset' => 'bundle',
            'kilo' => 'per kilo',
            'load' => 'per load',
            'piece' => 'per piece',
            default => 'fixed price',
        };
    }

    public function pickupSlotLabel(): string
    {
        return self::PICKUP_SLOTS[$this->pickup_slot] ?? (string) $this->pickup_slot;
    }

    public function deliverySlotLabel(): ?string
    {
        return $this->delivery_slot ? (self::PICKUP_SLOTS[$this->delivery_slot] ?? $this->delivery_slot) : null;
    }

    public function wantsDelivery(): bool
    {
        return $this->delivery_preference === 'deliver';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'confirmed'], true);
    }

    public function isCancellable(): bool
    {
        return $this->status === 'pending' || $this->status === 'confirmed';
    }

    /**
     * The code that goes on the bag so this load cannot be confused with
     * another customer's.
     *
     * Short on purpose: a rider writes it on a tag with a marker, and a
     * customer reads it back over the phone. Derived from the booking
     * reference, which is already unique per day.
     */
    public function suggestedTagCode(): string
    {
        $tail = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $this->reference_no)) ?: '', -6);

        return 'CC-'.($tail ?: str_pad((string) $this->id, 6, '0', STR_PAD_LEFT));
    }

    /** What the branch and the customer both quote: the tag, or the booking. */
    public function handoffCode(): string
    {
        return $this->tag_code ?: $this->reference_no;
    }

    public static function nextReference(): string
    {
        // PU-YYMMDD-#### restarting each day, mirroring how job order numbers read.
        $today = now();
        $sequence = self::withTrashed()
            ->whereDate('created_at', $today->toDateString())
            ->count() + 1;

        return 'PU-'.$today->format('ymd').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
