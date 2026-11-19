<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Util;

final class DataForge
{
    public static function detectDelimiter(string $csv): string
    {
        $sample = substr($csv, 0, 8192);
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($sample, $candidate);
        }
        arsort($counts);
        $best = (string) array_key_first($counts);
        return ($counts[$best] ?? 0) > 0 ? $best : ',';
    }

    /**
     * @return array{headers:array<int,string>,rows:array<int,array<string,string>>,delimiter:string,bom:bool}
     */
    public static function parseCsv(string $csv, ?string $delimiter = null): array
    {
        $bom = str_starts_with($csv, "\xEF\xBB\xBF");
        if ($bom) {
            $csv = substr($csv, 3);
        }
        $delimiter ??= self::detectDelimiter($csv);
        $records = [];
        $field = '';
        $record = [];
        $inQuotes = false;
        $length = strlen($csv);
        for ($i = 0; $i < $length; $i++) {
            $char = $csv[$i];
            if ($inQuotes) {
                if ($char === '"') {
                    if ($i + 1 < $length && $csv[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $char;
                }
                continue;
            }
            if ($char === '"') {
                $inQuotes = true;
                continue;
            }
            if ($char === $delimiter) {
                $record[] = $field;
                $field = '';
                continue;
            }
            if ($char === "\n" || $char === "\r") {
                if ($char === "\r" && $i + 1 < $length && $csv[$i + 1] === "\n") {
                    $i++;
                }
                $record[] = $field;
                $field = '';
                if ($record !== ['']) {
                    $records[] = $record;
                }
                $record = [];
                continue;
            }
            $field .= $char;
        }
        if ($field !== '' || $record !== []) {
            $record[] = $field;
            if ($record !== ['']) {
                $records[] = $record;
            }
        }
        if ($records === []) {
            return ['headers' => [], 'rows' => [], 'delimiter' => $delimiter, 'bom' => $bom];
        }
        $headers = array_map(static fn ($h) => trim((string) $h), array_shift($records));
        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($record[$index] ?? '');
            }
            $rows[] = $row;
        }
        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter, 'bom' => $bom];
    }

    public static function cleanValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        return trim($value);
    }

    public static function normalizeNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $clean = trim($value, "() \t");
        $hasComma = str_contains($clean, ',');
        $hasDot = str_contains($clean, '.');
        if ($hasComma && $hasDot) {
            if (strrpos($clean, ',') > strrpos($clean, '.')) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($hasComma) {
            $parts = explode(',', $clean);
            $clean = (count($parts) === 2 && strlen(end($parts)) !== 3)
                ? str_replace(',', '.', $clean)
                : str_replace(',', '', $clean);
        }
        $clean = preg_replace('/[^0-9.\-]/', '', $clean) ?? '';
        if ($clean === '' || !is_numeric($clean)) {
            return $value;
        }
        return $negative ? '-' . ltrim($clean, '-') : $clean;
    }

    public static function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $m)) {
            $first = (int) $m[1];
            $second = (int) $m[2];
            $month = $first > 12 ? $second : $first;
            $day = $first > 12 ? $first : $second;
            return sprintf('%04d-%02d-%02d', (int) $m[3], $month, $day);
        }
        return $value;
    }

    /**
     * @return array{rows:array<int,array<string,string>>,report:array<string,mixed>}
     */
    public static function clean(array $parsed, array $options = []): array
    {
        $rows = $parsed['rows'];
        $headers = $parsed['headers'];
        $numberColumns = (array) ($options['numbers'] ?? []);
        $dateColumns = (array) ($options['dates'] ?? []);
        $dedupeKeys = (array) ($options['dedupe'] ?? []);
        $mapping = (array) ($options['map'] ?? []);
        $inRows = count($rows);
        $errors = [];
        $seen = [];
        $out = [];
        foreach ($rows as $index => $row) {
            $clean = [];
            foreach ($headers as $header) {
                $value = self::cleanValue((string) ($row[$header] ?? ''));
                if (in_array($header, $numberColumns, true)) {
                    $normalized = self::normalizeNumber($value);
                    if ($normalized !== $value && $normalized !== '' && !is_numeric($normalized)) {
                        $errors[] = ['row' => $index + 2, 'column' => $header, 'value' => $value, 'error' => 'not a number'];
                    }
                    $value = $normalized;
                }
                if (in_array($header, $dateColumns, true)) {
                    $value = self::normalizeDate($value);
                }
                $clean[$header] = $value;
            }
            if ($dedupeKeys !== []) {
                $keyParts = [];
                foreach ($dedupeKeys as $key) {
                    $keyParts[] = strtolower($clean[$key] ?? '');
                }
                $key = implode("\x1f", $keyParts);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
            }
            if ($mapping !== []) {
                $mapped = [];
                foreach ($clean as $key => $value) {
                    $mapped[$mapping[$key] ?? $key] = $value;
                }
                $clean = $mapped;
            }
            $out[] = $clean;
        }
        $outHeaders = $out !== [] ? array_keys($out[0]) : $headers;
        if ($mapping !== []) {
            $outHeaders = array_values(array_map(static fn ($h) => $mapping[$h] ?? $h, $headers));
        }
        return [
            'headers' => $outHeaders,
            'rows' => $out,
            'report' => [
                'rows_in' => $inRows,
                'rows_out' => count($out),
                'duplicates' => $inRows - count($out),
                'errors' => $errors,
                'delimiter' => $parsed['delimiter'],
                'bom' => $parsed['bom'],
            ],
        ];
    }

    public static function toCsv(array $headers, array $rows): string
    {
        $lines = [Util::csvRow($headers)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $header) {
                $cells[] = (string) ($row[$header] ?? '');
            }
            $lines[] = Util::csvRow($cells);
        }
        return implode("\n", $lines) . "\n";
    }

    public static function cli(array $args): int
    {
        $cmd = $args[0] ?? 'help';
        $options = Cli::options(array_slice($args, 1));
        if ($cmd === 'clean') {
            $in = (string) ($options['in'] ?? '');
            if ($in === '' || !is_file($in)) {
                Cli::err('Provide --in=file.csv');
                return 2;
            }
            $parsed = self::parseCsv((string) file_get_contents($in), isset($options['delimiter']) ? (string) $options['delimiter'] : null);
            $dedupe = isset($options['dedupe']) ? array_map('trim', explode(',', (string) $options['dedupe'])) : [];
            $numbers = isset($options['numbers']) ? array_map('trim', explode(',', (string) $options['numbers'])) : [];
            $dates = isset($options['dates']) ? array_map('trim', explode(',', (string) $options['dates'])) : [];
            $map = [];
            if (isset($options['map']) && is_file((string) $options['map'])) {
                $decoded = json_decode((string) file_get_contents((string) $options['map']), true);
                $map = is_array($decoded) ? $decoded : [];
            }
            $result = self::clean($parsed, ['dedupe' => $dedupe, 'numbers' => $numbers, 'dates' => $dates, 'map' => $map]);
            $csv = self::toCsv($result['headers'], $result['rows']);
            $out = (string) ($options['out'] ?? '');
            if ($out !== '') {
                Util::atomicWrite($out, $csv);
                Cli::out('written: ' . $out);
            } else {
                Cli::out($csv);
            }
            Cli::err(Util::json($result['report'], JSON_PRETTY_PRINT));
            return 0;
        }
        if ($cmd === 'stats') {
            $in = (string) ($options['in'] ?? '');
            if ($in === '' || !is_file($in)) {
                Cli::err('Provide --in=file.csv');
                return 2;
            }
            $parsed = self::parseCsv((string) file_get_contents($in));
            Cli::out(Util::json([
                'rows' => count($parsed['rows']),
                'columns' => $parsed['headers'],
                'delimiter' => $parsed['delimiter'],
                'bom' => $parsed['bom'],
            ], JSON_PRETTY_PRINT));
            return 0;
        }
        Cli::out("Usage: aiopskit dataforge <clean|stats>\n\n" .
            "  clean --in=raw.csv --out=clean.csv [--dedupe=email] [--numbers=amount] [--dates=date] [--map=map.json]\n" .
            "  stats --in=raw.csv");
        return 0;
    }

    public static function web(array $query, array $post): array
    {
        $result = null;
        if (isset($post['csv']) && is_string($post['csv']) && trim($post['csv']) !== '') {
            $parsed = self::parseCsv($post['csv'], isset($post['delimiter']) && $post['delimiter'] !== '' ? (string) $post['delimiter'] : null);
            $dedupe = isset($post['dedupe']) ? array_map('trim', explode(',', (string) $post['dedupe'])) : [];
            $numbers = isset($post['numbers']) ? array_map('trim', explode(',', (string) $post['numbers'])) : [];
            $dates = isset($post['dates']) ? array_map('trim', explode(',', (string) $post['dates'])) : [];
            $result = self::clean($parsed, ['dedupe' => $dedupe, 'numbers' => $numbers, 'dates' => $dates]);
        }
        return ['result' => $result];
    }
}
