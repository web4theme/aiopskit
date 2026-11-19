<?php
declare(strict_types=1);

namespace AIOpsKit\Apps;

use AIOpsKit\Cli;
use AIOpsKit\Util;

final class UsageDashboard
{
    public static function snapshot(int $days = 30, ?string $path = null): array
    {
        $summary = TokenLedger::summary($days, null, $path);
        $daily = TokenLedger::daily($days, $path);
        $byModel = [];
        $total = 0;
        foreach ($summary['rows'] as $row) {
            $model = (string) $row['model'];
            $byModel[$model] = ($byModel[$model] ?? 0) + (int) $row['cost_micros'];
            $total += (int) $row['cost_micros'];
        }
        arsort($byModel);
        $series = [];
        $indexed = [];
        foreach ($daily as $row) {
            $indexed[(string) $row['day']] = (int) $row['micros'];
        }
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $series[] = ['day' => $day, 'micros' => $indexed[$day] ?? 0];
        }
        return [
            'days' => $days,
            'total_micros' => $total,
            'calls' => array_sum(array_map(static fn ($r) => (int) $r['calls'], $summary['rows'])),
            'by_model' => $byModel,
            'series' => $series,
            'rows' => $summary['rows'],
        ];
    }

    public static function bars(array $series): string
    {
        $width = 760;
        $height = 180;
        $pad = 24;
        $count = max(1, count($series));
        $max = 1;
        foreach ($series as $point) {
            $max = max($max, (int) $point['micros']);
        }
        $barWidth = max(2.0, ($width - $pad * 2) / $count - 2);
        $bars = '';
        foreach ($series as $i => $point) {
            $value = (int) $point['micros'];
            $h = (int) round(($height - $pad * 2) * $value / $max);
            $x = $pad + $i * (($width - $pad * 2) / $count);
            $y = $height - $pad - $h;
            $bars .= '<rect x="' . round($x, 1) . '" y="' . $y . '" width="' . round($barWidth, 1) . '" height="' . $h . '" fill="#2563eb" rx="2"><title>' . Util::e((string) $point['day']) . ': $' . number_format($value / 1000000, 4, '.', '') . '</title></rect>';
        }
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Daily AI spend" style="width:100%;height:auto">'
            . '<line x1="' . $pad . '" y1="' . ($height - $pad) . '" x2="' . ($width - $pad) . '" y2="' . ($height - $pad) . '" stroke="#d1d5db"/>'
            . $bars . '</svg>';
    }

    public static function money(int $micros): string
    {
        return '$' . number_format($micros / 1000000, 4, '.', '');
    }

    public static function cli(array $args): int
    {
        $options = Cli::options(array_slice($args, 1));
        $days = max(1, min(365, (int) ($options['days'] ?? 30)));
        $snapshot = self::snapshot($days);
        if (($args[0] ?? '') === 'html') {
            Cli::out('<svg viewBox="0 0 760 180">' . strip_tags(self::bars($snapshot['series']), '<rect><title><line>') . '</svg>');
            return 0;
        }
        Cli::out('days: ' . $days . '  calls: ' . $snapshot['calls'] . '  spend: ' . self::money($snapshot['total_micros']));
        foreach ($snapshot['by_model'] as $model => $micros) {
            Cli::out('  ' . str_pad($model, 28) . self::money((int) $micros));
        }
        Cli::out('Usage: aiopskit dashboard [--days=30] [html]');
        return 0;
    }
}
