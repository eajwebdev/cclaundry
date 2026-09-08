<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PickupRequest extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'confirmed', 'picked_up', 'completed', 'cancelled'];

    public const SERVICE_TYPES = [
        'wash_dry_fold' => 'Wash, Dry & Fold',
        'wash_only' => 'Wash Only',
        'dry_only' => 'Dry Only',
        'press_iron' => 'Steam Press / Ironing',
        'handwash' => 'Handwash & Delicates',
        'comforter' => 'Comforters & Beddings',
    ];

    public const PICKUP_SLOTS = [
        'morning' => '8:00 AM - 11:00 AM',
        'afternoon' => '1:00 PM - 4:00 PM',
        'evening' => '4:00 PM - 7:00 PM',
    ];

    protected $fillable = [
        'reference_no', 'customer_id', 'branch_id', 'laundry_service_id',
        'service_type', 'estimated_kilos',
        'contact_name', 'contact_phone', 'contact_email',
        'pickup_address', 'pickup_landmark', 'pickup_date', 'pickup_slot',
        'delivery_preference', 'delivery_address', 'delivery_date', 'delivery_slot',
        'is_rush', 'notes', 'estimated_total',
        'status', 'job_order_id', 'handled_by',
        'confirmed_at', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'estimated_kilos' => 'decimal:2',
        'estimated_total' => 'decimal:2',
        'is_rush' => 'boolean',
        'pickup_date' => 'date',
        'delivery_date' => 'date',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function service() { return $this->belongsTo(LaundryService::class, 'laundry_service_id'); }
    public function jobOrder() { return $this->belongsTo(JobOrder::class); }
    public function handler() { return $this->belongsTo(User::class, 'handled_by'); }

    public function serviceTypeLabel(): string
    {
        return self::SERVICE_TYPES[$this->service_type] ?? ucfirst(str_replace('_', ' ', (string) $this->service_type));
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
