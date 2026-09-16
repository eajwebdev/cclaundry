<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaundryService extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'service_category_id', 'name', 'report_category', 'pricing_type', 'price', 'minimum_kilos', 'kilos_per_load', 'price_unit_label',
        'is_active', 'show_on_landing', 'landing_blurb', 'landing_icon', 'landing_sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'minimum_kilos' => 'decimal:2',
        'kilos_per_load' => 'decimal:2',
        'is_active' => 'boolean',
        'show_on_landing' => 'boolean',
        'landing_sort_order' => 'integer',
    ];

    /** Icons a service can wear on the landing page (keys from resources/js/app.js). */
    public const LANDING_ICONS = [
        'laundry' => 'Washing machine',
        'shirt' => 'Shirt',
        'droplets' => 'Droplets',
        'wind' => 'Wind / drying',
        'flame' => 'Flame / pressing',
        'sparkles' => 'Sparkles / delicates',
        'bed-double' => 'Bed / beddings',
        'package' => 'Package',
        'truck' => 'Truck / delivery',
        'scale' => 'Scale / by weight',
    ];

    public function scopeOnLanding($query)
    {
        return $query->where('is_active', true)
            ->where('show_on_landing', true)
            ->orderBy('landing_sort_order')
            ->orderBy('name');
    }

    /** Falls back to a sensible icon when staff have not chosen one. */
    public function landingIcon(): string
    {
        if ($this->landing_icon && array_key_exists($this->landing_icon, self::LANDING_ICONS)) {
            return $this->landing_icon;
        }

        $name = mb_strtolower((string) $this->name);

        return match (true) {
            str_contains($name, 'dry') => 'wind',
            str_contains($name, 'iron'), str_contains($name, 'press') => 'flame',
            str_contains($name, 'handwash'), str_contains($name, 'delicate') => 'sparkles',
            str_contains($name, 'comforter'), str_contains($name, 'blanket'), str_contains($name, 'bed') => 'bed-double',
            str_contains($name, 'fold') => 'package',
            str_contains($name, 'wash') => 'droplets',
            default => 'laundry',
        };
    }

    /**
     * How many kilos one load holds, for a load-priced service: 10 kg a load
     * means 1 to 10 kg is one load and 11 to 20 kg is two.
     */
    public function kilosPerLoad(): float
    {
        return $this->kilos_per_load !== null && (float) $this->kilos_per_load > 0
            ? (float) $this->kilos_per_load
            : (float) \App\Support\Booking::DEFAULT_KILOS_PER_LOAD;
    }

    /** How the price reads to a customer, e.g. "per load". */
    public function priceUnitLabel(): string
    {
        // Set per service where the pricing type's own wording is wrong:
        // steaming bills per item but is sold per pair.
        if (filled($this->price_unit_label)) {
            return $this->price_unit_label;
        }

        return match ($this->pricing_type) {
            'kilo' => 'per kilo',
            'load' => 'per load',
            'piece' => 'per piece',
            default => 'fixed price',
        };
    }

    /** The same unit, abbreviated for the price-list tiles: kg, load, pc, pair. */
    public function priceUnitShort(): string
    {
        if (filled($this->price_unit_label)) {
            return trim(preg_replace('/^per\s+/i', '', $this->price_unit_label));
        }

        return match ($this->pricing_type) {
            'kilo' => 'kg',
            'load' => 'load',
            'piece' => 'pc',
            default => '',
        };
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function serviceCategory() { return $this->belongsTo(LaundryServiceCategory::class, 'service_category_id'); }
    public function inventoryUsages() { return $this->hasMany(ServiceInventoryUsage::class); }
}
