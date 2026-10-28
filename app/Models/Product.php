<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['product_category_id', 'color_bar_rate_category_id', 'product_family', 'product_brand_id', 'preferred_supplier_id', 'name', 'brand', 'sku', 'description', 'image_paths', 'is_color_bar_usable', 'is_color_bar_supply', 'is_reception_usable', 'status'];

    protected function casts(): array
    {
        return ['is_color_bar_usable' => 'boolean', 'is_color_bar_supply' => 'boolean', 'is_reception_usable' => 'boolean', 'image_paths' => 'array'];
    }

    public function isColorBarSupply(): bool
    {
        return $this->is_color_bar_supply ?? $this->is_color_bar_usable ?? false;
    }

    #[Scope]
    protected function colorBarSupplies(Builder $query): Builder
    {
        return $query->where(fn (Builder $products) => $products
            ->where('is_color_bar_supply', true)
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('is_color_bar_supply')->where('is_color_bar_usable', true)));
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function colorBarRateCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'color_bar_rate_category_id');
    }

    public function brandCatalog(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'product_brand_id');
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }
}
