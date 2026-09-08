<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LaundryService extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'service_category_id', 'name', 'report_category', 'pricing_type', 'price', 'is_active',
        'show_on_landing', 'landing_blurb', 'landing_icon', 'landing_sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
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

    /** How the price reads to a customer, e.g. "per load". */
    public function priceUnitLabel(): string
    {
        return match ($this->pricing_type) {
            'kilo' => 'per kilo',
            'load' => 'per load',
            'piece' => 'per piece',
            default => 'fixed price',
        };
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function serviceCategory() { return $this->belongsTo(LaundryServiceCategory::class, 'service_category_id'); }
    public function inventoryUsages() { return $this->hasMany(ServiceInventoryUsage::class); }
}
