<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable, SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'phone', 'email', 'address', 'latitude', 'longitude',
        'billing_type', 'unpaid_limit', 'is_active',
        'password', 'registered_at', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'unpaid_limit' => 'decimal:2',
        'is_active' => 'boolean',
        'password' => 'hashed',
        'registered_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function jobOrders() { return $this->hasMany(JobOrder::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function pickupRequests() { return $this->hasMany(PickupRequest::class); }

    public function canReceiveSms(): bool
    {
        return filled($this->phone);
    }

    /**
     * A walk-in record created at the counter has no password yet. The person
     * can claim it from the public site to keep their existing order history.
     */
    public function hasPortalAccount(): bool
    {
        return filled($this->password);
    }

    /**
     * Philippine mobile numbers get typed a dozen different ways. Normalising
     * to a bare 11-digit 09xxxxxxxxx keeps lookups stable across the counter
     * and the public booking form.
     */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            return '0'.substr($digits, 2);
        }

        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0'.$digits;
        }

        return $digits;
    }

    public function scopeMatchingPhone($query, ?string $phone)
    {
        $normalized = self::normalizePhone($phone);

        if ($normalized === '') {
            return $query->whereRaw('1 = 0');
        }

        // Compare on digits only so 0917-123-4567 and +639171234567 both match.
        return $query->whereRaw(
            "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') IN (?, ?, ?)",
            [$normalized, '63'.ltrim($normalized, '0'), ltrim($normalized, '0')]
        );
    }
}
