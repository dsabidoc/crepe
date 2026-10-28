<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductSearch
{
    /** @return list<string> */
    public static function terms(string $search): array
    {
        return array_values(array_unique(array_filter(preg_split('/\s+/u', trim($search)) ?: [])));
    }

    public static function products(Builder $query, string $search): Builder
    {
        foreach (self::terms($search) as $term) {
            $query->where(function (Builder $part) use ($term): void {
                self::columns($part, ['name', 'brand', 'sku', 'product_family'], $term);
                $part->orWhereHas('variants', fn (Builder $variants) => self::columns($variants, ['sku', 'name'], $term));
            });
        }

        return $query;
    }

    public static function variants(Builder $query, string $search): Builder
    {
        foreach (self::terms($search) as $term) {
            $query->where(function (Builder $part) use ($term): void {
                self::columns($part, ['name', 'sku'], $term);
                $part->orWhereHas('product', fn (Builder $products) => self::columns($products, ['name', 'brand', 'sku', 'product_family'], $term));
            });
        }

        return $query;
    }

    /** @param list<string> $columns */
    private static function columns(Builder $query, array $columns, string $term): Builder
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower(Str::ascii($term))).'%';
        $query->where(function (Builder $nested) use ($columns, $like): void {
            foreach ($columns as $column) {
                $expression = $nested->getModel()->qualifyColumn($column);
                foreach (['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n'] as $from => $to) {
                    $expression = "REPLACE({$expression}, '{$from}', '{$to}')";
                }
                $nested->orWhereRaw("LOWER({$expression}) LIKE ? ESCAPE '!'", [$like]);
            }
        });

        return $query;
    }
}
