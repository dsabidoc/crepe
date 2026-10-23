<?php

namespace App\Services;

use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SpreadsheetFile
{
    public function __construct(private InventoryWorkbook $workbook) {}

    /** @param list<list<string|int|float|null>> $rows */
    public function writeCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->csvValue(...), $row), ',', '"', '', "\r\n");
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /** @return array<int, array<string, string>> */
    public function read(string $path, string $format, string $sheetName, int $columns): array
    {
        if ($format === 'xlsx') {
            return $this->workbook->read($path, $sheetName, $columns);
        }
        $stream = fopen($path, 'r');
        try {
            $first = fgets($stream);
            if ($first === false) {
                throw ValidationException::withMessages(['file' => 'El CSV está vacío.']);
            }
            $first = ltrim($first, "\xEF\xBB\xBF");
            $delimiter = count(str_getcsv($first, ';', '"', '')) > count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
            rewind($stream);
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $rows = [];
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $number = count($rows) + 1;
                if ($number > 10001 || count($values) > $columns) {
                    throw ValidationException::withMessages(['file' => "Fila {$number}: el archivo admite 10,000 registros y únicamente las columnas de la plantilla."]);
                }
                $row = [];
                foreach ($values as $column => $value) {
                    $value = (string) $value;
                    if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                        throw ValidationException::withMessages(['file' => "Fila {$number}: guarda el CSV con codificación UTF-8."]);
                    }
                    if (preg_match("/^'(?=\\s*[=+@\-]|[\t\r\n']|0[0-9])/u", $value)) {
                        $value = substr($value, 1);
                    }
                    $row[InventoryWorkbook::columnName($column)] = trim($value);
                }
                $rows[$number] = $row;
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function download(string $content, string $filename, string $format): Response
    {
        return response($content, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.'.$format.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csvValue(string|int|float|null $value): string
    {
        $text = (string) $value;
        if (is_string($value) && preg_match('/^[\s]*[=+@\-]|^[\t\r\n\']|^0[0-9]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }
}
