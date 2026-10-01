<?php

namespace App\Services;

use App\Models\PurchaseOrder;

class PurchaseOrderPdfService
{
    public function render(PurchaseOrder $purchaseOrder): string
    {
        $purchaseOrder->loadMissing('supplier', 'items.variant.product');

        $content = [
            '0.15 0.18 0.27 rg',
            $this->text('ORDEN DE COMPRA', 50, 752, 20, 'F2'),
            $this->text($purchaseOrder->code, 50, 728, 11, 'F1'),
            $this->text('Proveedor: '.$purchaseOrder->supplier->name, 50, 704, 11, 'F1'),
            $this->text('Fecha: '.$purchaseOrder->created_at->translatedFormat('d \\de F \\de Y'), 50, 686, 10, 'F1'),
            '0.76 0.30 0.47 rg',
            '50 666 512 2 re f',
            '0.95 0.96 0.98 rg',
            '50 632 512 26 re f',
            '0.15 0.18 0.27 rg',
            $this->text('PRODUCTO', 62, 642, 9, 'F2'),
            $this->text('PRESENTACION', 330, 642, 9, 'F2'),
            $this->text('CANTIDAD', 454, 642, 9, 'F2'),
            $this->text('COSTO EST.', 510, 642, 9, 'F2'),
        ];

        $y = 616;
        foreach ($purchaseOrder->items as $item) {
            if ($y < 130) {
                break;
            }

            $content[] = '0.86 0.88 0.92 RG';
            $content[] = sprintf('50 %s m 562 %s l S', $y - 12, $y - 12);
            $content[] = '0.15 0.18 0.27 rg';
            $content[] = $this->text($item->variant->product->name, 62, $y, 10, 'F1');
            $content[] = $this->text($item->variant->name, 330, $y, 10, 'F1');
            $content[] = $this->text((string) $item->ordered_quantity, 454, $y, 10, 'F1');
            $content[] = $this->text('$'.number_format((float) $item->cost_snapshot, 2), 510, $y, 10, 'F1');
            $y -= 30;
        }

        if ($purchaseOrder->notes) {
            $content[] = '0.15 0.18 0.27 rg';
            $content[] = $this->text('Notas: '.$purchaseOrder->notes, 50, 95, 9, 'F1');
        }

        return $this->document(implode("\n", $content));
    }

    private function text(string $value, int $x, int $y, int $size, string $font): string
    {
        return sprintf('BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET', $font, $size, $x, $y, $this->escape($value));
    }

    private function escape(string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value) ?: $value;

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $encoded);
    }

    private function document(string $content): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length '.strlen($content)." >>\nstream\n".$content."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";
    }
}
