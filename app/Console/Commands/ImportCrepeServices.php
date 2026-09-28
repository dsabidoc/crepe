<?php

namespace App\Console\Commands;

use App\Models\SalonService;
use App\Models\ServiceCategory;
use Illuminate\Console\Command;

class ImportCrepeServices extends Command
{
    protected $signature = 'crepe:import-services {path : Ruta al CSV exportado de CrepeManager}';

    protected $description = 'Importa categorías, precios fijos o variables y costos de servicios de CrepeManager';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No fue posible leer el archivo CSV indicado.');

            return self::FAILURE;
        }

        $categories = [];
        $handle = fopen($path, 'r');
        $imported = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $sourceId = trim((string) ($row[9] ?? ''));
            $name = trim((string) ($row[10] ?? ''));
            $categoryName = trim((string) ($row[11] ?? ''));
            if (! ctype_digit($sourceId) || (int) $sourceId < 1 || $name === '' || $categoryName === '') {
                continue;
            }

            if (! isset($categories[$categoryName])) {
                $categories[$categoryName] = ServiceCategory::query()->firstOrCreate(['name' => $categoryName], [
                    'color' => $this->colorFor($categoryName), 'sort_order' => count($categories) + 10, 'is_active' => true,
                ]);
            }
            $isVariable = mb_strtoupper(trim((string) ($row[13] ?? ''))) !== 'FIJO';
            $service = SalonService::query()->updateOrCreate(['name' => $name], [
                'service_category_id' => $categories[$categoryName]->id,
                'description' => "Importado de CrepeManager (ID {$sourceId})",
                'base_price' => $this->amount($row[12] ?? ''),
                'base_cost' => $this->amount($row[14] ?? ''),
                'estimated_duration_minutes' => $this->durationFor($categoryName),
                'price_type' => $isVariable ? 'variable' : 'fixed',
                'requires_color_bar' => in_array($categoryName, ['Colorimetría', 'Keratinas'], true),
                'status' => 'active',
            ]);
            if (! $isVariable) {
                foreach (['A', 'B', 'C'] as $tier) {
                    $service->prices()->updateOrCreate(['tier' => $tier], [
                        'cost' => $service->base_cost,
                        'sale_price' => $service->base_price,
                    ]);
                }
            }
            $imported++;
        }
        fclose($handle);
        $this->info("Servicios importados: {$imported}. Las duraciones iniciales se pueden ajustar desde la ficha de cada servicio.");

        return self::SUCCESS;
    }

    private function amount(mixed $value): float
    {
        $digits = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($digits) ? (float) $digits : 0.0;
    }

    private function durationFor(string $category): int
    {
        return match ($category) {
            'Cejas y Pestañas' => 60, 'Colorimetría' => 180, 'Cortes de cabello' => 60,
            'Depilados' => 30, 'Keratinas' => 180, 'Maquillajes' => 60, 'Peinados' => 60,
            'Tratamiento de cabello' => 60, 'Uñas' => 60, default => 60,
        };
    }

    private function colorFor(string $category): string
    {
        return match ($category) {
            'Cejas y Pestañas' => '#9A5EEA', 'Colorimetría' => '#D87597', 'Cortes de cabello' => '#2F63F5',
            'Depilados' => '#C98216', 'Keratinas' => '#1A9B74', 'Maquillajes' => '#C25A91',
            'Peinados' => '#6B9DEA', 'Tratamiento de cabello' => '#45A97F', 'Uñas' => '#7759C9', default => '#2F63F5',
        };
    }
}
