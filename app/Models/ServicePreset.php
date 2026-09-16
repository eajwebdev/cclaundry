<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServicePreset extends Model
{
    protected $fillable = [
        'branch_id', 'service_category_id', 'name', 'sort_order', 'is_active',
        'show_on_landing', 'landing_blurb', 'landing_icon', 'landing_sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'show_on_landing' => 'boolean',
        'landing_sort_order' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceCategory()
    {
        return $this->belongsTo(LaundryServiceCategory::class, 'service_category_id');
    }

    public function items()
    {
        return $this->hasMany(ServicePresetItem::class);
    }

    public function scopeOnLanding($query)
    {
        return $query->where('is_active', true)
            ->where('show_on_landing', true)
            ->orderBy('landing_sort_order')
            ->orderBy('name');
    }

    /**
     * What the bundle costs: the sum of each service at its current price times
     * the quantity the preset includes. Derived rather than stored, so a price
     * change on any component is reflected immediately.
     */
    public function totalPrice(): float
    {
        return round($this->items->sum(
            fn (ServicePresetItem $item) => (float) ($item->service?->price ?? 0) * (float) $item->quantity
        ), 2);
    }

    /**
     * Bundled services that can no longer be sold: deleted, switched off, or
     * moved out of the preset's branch. A preset with any of these would be
     * advertised at a price that leaves them out.
     *
     * @return array<int, string>
     */
    public function unavailableServiceNames(): array
    {
        return $this->items
            ->filter(fn (ServicePresetItem $item) => ! $item->service
                || ! $item->service->is_active
                || (int) $item->service->branch_id !== (int) $this->branch_id)
            ->map(fn (ServicePresetItem $item) => $item->service?->name ?? 'a deleted service')
            ->values()
            ->all();
    }

    /** Names of the services bundled in, for the landing page card. */
    public function includedServiceNames(): array
    {
        return $this->items
            ->filter(fn (ServicePresetItem $item) => $item->service !== null)
            ->map(fn (ServicePresetItem $item) => $item->service->name)
            ->values()
            ->all();
    }

    public function landingIcon(): string
    {
        if ($this->landing_icon && array_key_exists($this->landing_icon, LaundryService::LANDING_ICONS)) {
            return $this->landing_icon;
        }

        // A bundle reads best as a package unless it is obviously something else.
        $name = mb_strtolower((string) $this->name);

        return match (true) {
            str_contains($name, 'comforter'), str_contains($name, 'bed') => 'bed-double',
            str_contains($name, 'iron'), str_contains($name, 'press') => 'flame',
            default => 'package',
        };
    }
}
