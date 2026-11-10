<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Service;

class ExportService {
    public function __construct(private EntryService $entries) {
    }

    public function rows(string $uid, int $from, int $to): array {
        $rows = [[
            'Datum', 'Typ', 'Projekt', 'Beginn', 'Ende', 'Dauer exakt (Sek.)', 'Dauer gerundet (Sek.)'
        ]];
        foreach ($this->entries->list($uid, $from, $to) as $entry) {
            $end = (int)($entry['end_ts'] ?? time());
            $duration = max(0, $end - (int)$entry['start_ts']);
            $rounded = TimeMath::roundToQuarterHour($duration);
            $rows[] = [
                date('Y-m-d', (int)$entry['start_ts']),
                $entry['type'] === 'work' ? 'Arbeitszeit' : ($entry['type'] === 'pause' ? 'Pause' : 'Projekt'),
                $entry['project_name'] ?? '',
                date('Y-m-d H:i:s', (int)$entry['start_ts']),
                $entry['end_ts'] === null ? '' : date('Y-m-d H:i:s', (int)$entry['end_ts']),
                $duration,
                $rounded,
            ];
        }
        return $rows;
    }

    public function csv(array $rows): string {
        $stream = fopen('php://temp', 'r+');
        // UTF-8 BOM makes German umlauts display correctly in desktop Excel.
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return $content === false ? '' : $content;
    }

    public function xlsx(array $rows): string {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Die PHP-Erweiterung zip ist für den XLSX-Export nicht verfügbar.');
        }
        $path = tempnam(sys_get_temp_dir(), 'worktracker-') . '.xlsx';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('XLSX-Datei konnte nicht erstellt werden.');
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Zeiterfassung" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $row) {
            $sheet .= '<row r="' . ($r + 1) . '">';
            foreach ($row as $c => $value) {
                $ref = $this->columnName($c + 1) . ($r + 1);
                if (is_int($value) || is_float($value)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                        . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);
        if ($bytes === false) throw new \RuntimeException('XLSX-Datei konnte nicht gelesen werden.');
        return $bytes;
    }

    private function columnName(int $number): string {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }
}
