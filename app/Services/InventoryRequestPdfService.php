<?php

namespace App\Services;

use App\Models\InventoryRequest;

class InventoryRequestPdfService
{
    public function render(InventoryRequest $inventoryRequest): string
    {
        $inventoryRequest->load([
            'sourceLocation', 'requester', 'items.variant.product.preferredSupplier',
            'items.variant.balances' => fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', 'ALM')),
        ]);
        $pages = [];
        [$content, $y] = $this->pageHeader($inventoryRequest);

        foreach ($inventoryRequest->items as $index => $item) {
            $variant = $item->variant;
            $product = $variant->product;
            $name = $this->wrap(($index + 1).'. '.$product->name, 268, 11);
            $details = array_merge(
                $this->wrap('Presentación: '.$variant->name, 268, 9),
                $this->wrap('Marca: '.($product->brand ?: 'Sin marca'), 268, 9),
                $this->wrap('Proveedor: '.($product->preferredSupplier?->name ?: 'Sin proveedor'), 268, 9),
            );
            $stock = (float) $variant->balances->first()?->available_quantity;
            $unitMode = $item->requested_units !== null;
            $requested = $unitMode ? $item->requested_units : (float) $item->requested_quantity;
            $available = $unitMode ? floor($stock / max((float) $variant->content_quantity, .001)) : $stock;
            $unit = $unitMode ? 'unidades' : $item->unit;
            $height = max(76, count($name) * 14 + count($details) * 12 + 22);

            if ($y - $height < 58) {
                $pages[] = $content;
                [$content, $y] = $this->pageHeader($inventoryRequest);
            }

            $lineY = $y - 18;
            $content[] = '0.15 0.18 0.27 rg';
            foreach ($name as $line) {
                $content[] = $this->text($line, 46, $lineY, 11, 'F2');
                $lineY -= 14;
            }
            $content[] = '0.40 0.44 0.51 rg';
            foreach ($details as $line) {
                $content[] = $this->text($line, 46, $lineY, 9);
                $lineY -= 12;
            }
            $content[] = '0.15 0.18 0.27 rg';
            foreach ([[320, $requested], [407, $available]] as [$x, $quantity]) {
                $content[] = $this->text($this->quantity($quantity), $x, $y - 22, 11, 'F2');
                $content[] = $this->text($unit, $x, $y - 38, 8);
            }
            $content[] = '0.78 0.81 0.85 RG';
            $content[] = sprintf('501 %d m 566 %d l S', $y - 32, $y - 32);
            $content[] = sprintf('36 %d m 576 %d l S', $y - $height, $y - $height);
            $y -= $height;
        }

        $pages[] = $content;
        $total = count($pages);
        foreach ($pages as $index => &$page) {
            $page[] = '0.40 0.44 0.51 rg';
            $page[] = $this->text('Lista de preparación · No registra una entrega.', 36, 32, 8);
            $page[] = $this->text('Página '.($index + 1).' de '.$total, 500, 32, 8);
        }
        unset($page);

        return $this->document(array_map(fn (array $page): string => implode("\n", $page), $pages));
    }

    /** @return array{list<string>, int} */
    private function pageHeader(InventoryRequest $inventoryRequest): array
    {
        $content = [
            '0.15 0.18 0.27 rg',
            $this->text('CREPÉ', 36, 750, 20, 'F2'),
            $this->text('SOLICITUD DE ALMACÉN', 160, 750, 17, 'F2'),
            '0.76 0.30 0.47 rg',
            $this->text($inventoryRequest->code.' · '.$inventoryRequest->items->count().' productos', 36, 722, 11, 'F2'),
            '0.40 0.44 0.51 rg',
        ];
        $y = 701;
        $metadata = array_merge(
            $this->wrap('Destino: '.$inventoryRequest->sourceLocation->name, 530, 10),
            $this->wrap('Solicitó: '.$inventoryRequest->requester->name.' · '.$inventoryRequest->created_at->format('d/m/Y H:i'), 530, 10),
            $this->wrap('Disponible en Almacén al exportar: '.now()->format('d/m/Y H:i'), 530, 9),
        );
        foreach ($metadata as $line) {
            $content[] = $this->text($line, 36, $y, 10);
            $y -= 15;
        }
        $content[] = '0.96 0.93 0.94 rg';
        $content[] = sprintf('36 %d 540 27 re f', $y - 29);
        $content[] = '0.15 0.18 0.27 rg';
        foreach ([[46, 'PRODUCTO'], [320, 'SOLICITADO'], [407, 'DISPONIBLE'], [501, 'ENTREGADO']] as [$x, $label]) {
            $content[] = $this->text($label, $x, $y - 18, 8, 'F2');
        }

        return [$content, $y - 29];
    }

    private function quantity(float|int $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');
    }

    /** @return list<string> */
    private function wrap(string $value, int $width, int $size): array
    {
        $lines = [];
        $line = '';
        $lineWidth = 0;
        $words = preg_split('/\s+/u', trim($value)) ?: [];
        foreach ($words as $word) {
            $candidate = ($line === '' ? '' : ' ').$word;
            if ($line !== '' && $lineWidth + $this->textWidth($candidate, $size) > $width) {
                $lines[] = $line;
                $line = '';
                $lineWidth = 0;
                $candidate = $word;
            }
            foreach (mb_str_split($candidate) as $character) {
                $characterWidth = $this->textWidth($character, $size);
                if ($line !== '' && $lineWidth + $characterWidth > $width) {
                    $lines[] = $line;
                    $line = '';
                    $lineWidth = 0;
                }
                $line .= $character;
                $lineWidth += $characterWidth;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function textWidth(string $value, int $size): float
    {
        $width = 0;
        foreach (mb_str_split($value) as $character) {
            $width += match (true) {
                str_contains('MW@%&', $character) => 1.02,
                str_contains(" ilI.,:;!'|", $character) => .34,
                ctype_upper($character) => .80,
                ctype_lower($character), ctype_digit($character) => .64,
                default => 1.02,
            } * $size;
        }

        return $width;
    }

    private function text(string $value, int $x, int $y, int $size, string $font = 'F1'): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: $value;
        $escaped = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $encoded);

        return sprintf('BT /%s %d Tf 1 0 0 1 %d %d Tm (%s) Tj ET', $font, $size, $x, $y, $escaped);
    }

    /** @param list<string> $pages */
    private function document(array $pages): string
    {
        $pageReferences = [];
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        foreach ($pages as $content) {
            $pageId = count($objects) + 1;
            $pageReferences[] = $pageId.' 0 R';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($pageId + 1).' 0 R >>';
            $objects[] = '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream";
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $pageReferences).'] /Count '.count($pages).' >>';
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";
    }
}
