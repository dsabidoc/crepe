<?php

namespace App\Services;

use App\Models\PayrollItem;

class PayrollReceiptPdfService
{
    public function render(PayrollItem $payrollItem): string
    {
        $payrollItem->loadMissing('payrollRun', 'employee');

        $run = $payrollItem->payrollRun;
        $name = $payrollItem->employee_name_snapshot;
        $period = $run->period_starts_on->translatedFormat('d \de F \de Y').' al '.$run->period_ends_on->translatedFormat('d \de F \de Y');
        $rows = [
            ['Sueldo base', $payrollItem->base_pay],
            ['Comisiones por servicios', $payrollItem->service_commissions],
            ['Comisiones por productos', $payrollItem->product_commissions],
            ['Descuento Infonavit', -$payrollItem->infonavit_deduction],
            ['Otros descuentos', -$payrollItem->other_deductions],
            ['Retardos', -$payrollItem->tardiness_deduction],
        ];

        $content = [
            '0.15 0.18 0.27 rg',
            $this->text($name, 50, 710, 12, 'F1'),
            $this->text('RECIBO DE NOMINA', 50, 752, 20, 'F2'),
            $this->text('Periodo: '.$period, 50, 730, 10, 'F1'),
            '0.76 0.30 0.47 rg',
            '50 690 512 2 re f',
            '0.95 0.96 0.98 rg',
            '50 650 512 26 re f',
            '0.15 0.18 0.27 rg',
            $this->text('CONCEPTO', 62, 660, 9, 'F2'),
            $this->text('MONTO', 438, 660, 9, 'F2'),
        ];

        $y = 630;
        foreach ($rows as [$label, $amount]) {
            $content[] = '0.86 0.88 0.92 RG';
            $content[] = sprintf('50 %s m 562 %s l S', $y - 10, $y - 10);
            $content[] = '0.15 0.18 0.27 rg';
            $content[] = $this->text($label, 62, $y, 10, 'F1');
            $content[] = $this->text($this->money((float) $amount), 438, $y, 10, 'F1');
            $y -= 34;
        }

        $content[] = '0.97 0.91 0.94 rg';
        $content[] = '50 '.($y - 18).' 512 42 re f';
        $content[] = '0.15 0.18 0.27 rg';
        $content[] = $this->text('TOTAL NETO A RECIBIR', 62, $y, 11, 'F2');
        $content[] = '0.76 0.30 0.47 rg';
        $content[] = $this->text($this->money((float) $payrollItem->total), 425, $y, 14, 'F2');
        $content[] = '0.15 0.18 0.27 rg';
        $content[] = $this->text('Declaro haber recibido de conformidad el importe indicado en este recibo.', 50, 305, 9, 'F1');
        $content[] = '0.38 0.42 0.50 RG';
        $content[] = '115 205 m 495 205 l S';
        $content[] = '0.15 0.18 0.27 rg';
        $content[] = $this->text('Firma de recibido', 240, 187, 10, 'F1');
        $content[] = $this->text('Nombre completo: '.$name, 50, 145, 10, 'F1');
        $content[] = $this->text('Fecha de emision: '.now()->translatedFormat('d \de F \de Y'), 50, 125, 10, 'F1');

        return $this->document(implode("\n", $content));
    }

    private function text(string $value, int $x, int $y, int $size, string $font): string
    {
        return sprintf('BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET', $font, $size, $x, $y, $this->escape($value));
    }

    private function money(float $amount): string
    {
        return ($amount < 0 ? '-' : '').'$'.number_format(abs($amount), 2);
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
