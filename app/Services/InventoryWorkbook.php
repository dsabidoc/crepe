<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use SimpleXMLElement;
use XMLWriter;
use ZipArchive;

class InventoryWorkbook
{
    public const Headers = ['ID', 'SKU', 'Producto', 'Presentación', 'Marca', 'Categoría', 'Proveedor', 'Unidad', 'Existencia original', 'Existencia nueva', 'Mínimo', 'Máximo', 'Control'];

    /** @param list<list<string|int|float>> $rows */
    public function write(array $rows, array $headers = self::Headers, string $sheetName = 'Inventario', ?array $instructions = null, array $editableColumns = [9], array $numericColumns = [0, 8, 9, 10, 11], array $lists = [], array $precisions = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'inventory-');
        if ($path === false) {
            throw new RuntimeException('No fue posible preparar el Excel.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No fue posible crear el Excel.');
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheetName, ENT_XML1).'" sheetId="1" r:id="rId1"/><sheet name="Instrucciones" sheetId="2" r:id="rId2"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet([$headers, ...$rows], true, $editableColumns, $numericColumns, $lists, $precisions));
            $zip->addFromString('xl/worksheets/sheet2.xml', $this->sheet($instructions ?? [
                ['INVENTARIO DE ALMACÉN · CREPÉ'],
                ['Descargado: '.now()->format('d/m/Y H:i')],
                ['Incluye todas las existencias de productos activos de Almacén, sin filtros ni paginación.'],
                ['1. Edita únicamente Existencia nueva (columna J, en verde). Es la cantidad final, no una entrada o salida.'],
                ['2. Usa unidades enteras desde 0 hasta 99999999999. Para vaciar una existencia escribe 0.'],
                ['3. Conserva los encabezados, ID, Existencia original, Control y el nombre de la hoja Inventario.'],
                ['4. Puedes ordenar filas completas o quitar las que no quieras actualizar. No se eliminan productos.'],
                ['5. Guarda como .xlsx. Sube el archivo, revisa la vista previa y confirma los cambios. No uses fórmulas.'],
                ['6. Si hubo movimientos desde la descarga, descarga de nuevo y actualiza el conteo.'],
                ['7. Producto, SKU, unidad, proveedor, mínimo y máximo son informativos; no se actualizan al importar.'],
                ['Cada ajuste se registra en la bitácora con usuario, motivo y cantidades anterior y nueva.'],
            ], false));
            if (! $zip->close()) {
                throw new RuntimeException('No fue posible guardar el Excel.');
            }

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    /** @return array<int, array<string, string>> */
    public function read(string $path, string $sheetName = 'Inventario', int $columnCount = 13): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->invalid('El archivo no es un Excel .xlsx válido.');
        }
        try {
            $size = 0;
            if ($zip->numFiles > 2048) {
                $this->invalid('El Excel contiene demasiados archivos internos.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $size += $zip->statIndex($index)['size'];
                if ($size > 30 * 1024 * 1024) {
                    $this->invalid('El contenido del Excel excede el tamaño permitido (30 MB).');
                }
            }
            $sheets = array_values(array_filter($this->xml($zip, 'xl/workbook.xml')->xpath('//*[local-name()="sheet"]'), fn (SimpleXMLElement $sheet): bool => (string) $sheet['name'] === $sheetName));
            if (count($sheets) !== 1) {
                $this->invalid("Conserva la hoja {$sheetName} del Excel descargado.");
            }
            $relationId = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $sheetPath = null;
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->children() as $relation) {
                if ((string) $relation['Id'] === $relationId && (string) $relation['TargetMode'] !== 'External') {
                    $target = (string) $relation['Target'];
                    if (preg_match('~^(?:/xl/)?worksheets/[a-zA-Z0-9_-]+\.xml$~D', $target)) {
                        $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                    }
                }
            }
            if ($sheetPath === null) {
                $this->invalid('No se pudo leer la hoja Inventario. Descarga una plantilla nueva.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->children() as $item) {
                    $strings[] = $this->text($item);
                }
            }
            $rows = $this->xml($zip, $sheetPath)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
            if (count($rows) > 10001) {
                $this->invalid('El Excel admite hasta 10,000 filas de inventario.');
            }
            $result = [];
            foreach ($rows as $row) {
                $rowNumber = (int) $row['r'];
                if ($rowNumber < 1 || isset($result[$rowNumber])) {
                    $this->invalid('El Excel contiene filas inválidas o repetidas.');
                }
                $values = [];
                foreach ($row->children() as $cell) {
                    if (! preg_match('/^([A-Z]+)[1-9][0-9]*$/D', (string) $cell['r'], $matches)) {
                        continue;
                    }
                    if ($this->columnIndex($matches[1]) >= $columnCount) {
                        $this->invalid("Fila {$rowNumber}: contiene columnas adicionales no reconocidas.");
                    }
                    if (isset($cell->f)) {
                        $this->invalid("Fila {$rowNumber}: usa valores, no fórmulas.");
                    }
                    $type = (string) $cell['t'];
                    $value = (string) $cell->v;
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                            $this->invalid("Fila {$rowNumber}: el Excel contiene un texto inválido.");
                        }
                        $value = $strings[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = $this->text($cell);
                    } elseif (! in_array($type, ['', 'n', 'str'], true)) {
                        $this->invalid("Fila {$rowNumber}: usa texto o números válidos.");
                    }
                    if (isset($values[$matches[1]])) {
                        $this->invalid("Fila {$rowNumber}: el Excel contiene celdas repetidas.");
                    }
                    $values[$matches[1]] = trim($value);
                }
                $result[$rowNumber] = $values;
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /** @param list<list<string|int|float>> $rows */
    private function sheet(array $rows, bool $inventory, array $editableColumns = [9], array $numericColumns = [0, 8, 9, 10, 11], array $lists = [], array $precisions = []): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->writeRaw($inventory ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' : '<sheetViews><sheetView workbookViewId="0"/></sheetViews>');
        $xml->startElement('cols');
        $widths = $inventory ? array_map(fn (string $header): int => min(44, max(18, mb_strlen($header) + 5)), $rows[0]) : [130];
        foreach ($widths as $index => $width) {
            $xml->writeRaw('<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"'.($inventory && ($rows[0][$index] ?? '') === 'Control' ? ' hidden="1"' : '').'/>');
        }
        $xml->endElement();
        $xml->startElement('sheetData');
        foreach ($rows as $index => $row) {
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) ($index + 1));
            $xml->writeAttribute('ht', $index === 0 ? '32' : '24');
            $xml->writeAttribute('customHeight', '1');
            foreach ($row as $column => $value) {
                $numeric = $inventory && $index > 0 && in_array($column, $numericColumns, true) && is_numeric($value);
                $editable = $inventory && in_array($column, $editableColumns, true);
                $style = $index === 0 ? ($editable ? 4 : 1) : ($editable ? 3 : ($numeric ? 2 : 0));
                if ($numeric && isset($precisions[$column])) {
                    $style = match ($precisions[$column]) {
                        0 => $editable ? 7 : 8,
                        4 => 5,
                        default => 6,
                    };
                }
                $xml->startElement('c');
                $xml->writeAttribute('r', self::columnName($column).($index + 1));
                $xml->writeAttribute('s', (string) $style);
                $xml->writeAttribute('t', $numeric ? 'n' : 'inlineStr');
                if ($numeric) {
                    $xml->writeElement('v', (string) $value);
                } else {
                    $xml->startElement('is');
                    $xml->writeElement('t', (string) $value);
                    $xml->endElement();
                }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        if ($inventory) {
            $xml->writeRaw('<autoFilter ref="A1:'.self::columnName(count($rows[0]) - 1).count($rows).'"/>');
            $hasQuantity = ($rows[0][9] ?? '') === 'Existencia nueva';
            if ($lists !== [] || $hasQuantity) {
                $xml->startElement('dataValidations');
                $xml->writeAttribute('count', (string) (count($lists) + (int) $hasQuantity));
                if ($hasQuantity) {
                    $xml->writeRaw('<dataValidation type="whole" operator="between" allowBlank="0" showErrorMessage="1" errorTitle="Cantidad inválida" error="Ingresa unidades enteras mayores o iguales a cero." sqref="J2:J10001"><formula1>0</formula1><formula2>99999999999</formula2></dataValidation>');
                }
                foreach ($lists as $column => $values) {
                    $xml->startElement('dataValidation');
                    $xml->writeAttribute('type', 'list');
                    $xml->writeAttribute('allowBlank', '1');
                    $xml->writeAttribute('showErrorMessage', '1');
                    $xml->writeAttribute('sqref', self::columnName($column).'2:'.self::columnName($column).'10001');
                    $xml->writeElement('formula1', '"'.implode(',', $values).'"');
                    $xml->endElement();
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function styles(): string
    {
        return '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.###"/><numFmt numFmtId="165" formatCode="#,##0.0000"/><numFmt numFmtId="166" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><color rgb="FF243447"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF243447"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8F5EE"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF237A57"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/><xf numFmtId="0" fontId="1" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="165" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/><xf numFmtId="166" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/><xf numFmtId="3" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if ($content === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            $this->invalid('El Excel está dañado o contiene una estructura no permitida.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $this->invalid('El Excel está dañado. Descarga una plantilla nueva.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(SimpleXMLElement $element): string
    {
        return implode('', array_map(fn (SimpleXMLElement $part): string => (string) $part, $element->xpath('.//*[local-name()="t"]')));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    public static function columnName(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function columnIndex(string $name): int
    {
        $index = 0;
        foreach (str_split($name) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }
}
