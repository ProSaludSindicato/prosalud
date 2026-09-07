<?php

namespace App\Support;

use App\Models\ConvenioEmailTracking;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class ConvenioSemesterPeriod
{
    public const ALL = 'todos';

    public static function current(?CarbonInterface $now = null): string
    {
        return self::fromDate($now ?? now());
    }

    public static function fromDate(CarbonInterface $date): string
    {
        $semester = (int) $date->format('n') <= 6 ? '1' : '2';

        return $date->format('Y').$semester;
    }

    public static function isValid(?string $periodo): bool
    {
        return is_string($periodo) && preg_match('/^\d{4}[12]$/', $periodo) === 1;
    }

    /**
     * @return array{start: Carbon, end: Carbon}|null
     */
    public static function dateRange(string $periodo): ?array
    {
        if (! self::isValid($periodo)) {
            return null;
        }

        $year = (int) substr($periodo, 0, 4);
        $semester = substr($periodo, 4, 1);

        if ($semester === '1') {
            return [
                'start' => Carbon::create($year, 1, 1)->startOfDay(),
                'end' => Carbon::create($year, 6, 30)->endOfDay(),
            ];
        }

        return [
            'start' => Carbon::create($year, 7, 1)->startOfDay(),
            'end' => Carbon::create($year, 12, 31)->endOfDay(),
        ];
    }

    public static function label(string $periodo): string
    {
        if ($periodo === self::ALL) {
            return 'Todos los semestres';
        }

        if (! self::isValid($periodo)) {
            return $periodo;
        }

        $year = substr($periodo, 0, 4);

        return substr($periodo, 4, 1) === '1'
            ? "1.er semestre {$year}"
            : "2.º semestre {$year}";
    }

    public static function previous(string $periodo): ?string
    {
        if (! self::isValid($periodo)) {
            return null;
        }

        return self::shift($periodo, -1);
    }

    public static function next(string $periodo): ?string
    {
        if (! self::isValid($periodo)) {
            return null;
        }

        return self::shift($periodo, 1);
    }

    /**
     * @return list<string>
     */
    public static function availableFromTracking(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $bounds = ConvenioEmailTracking::query()
            ->selectRaw('MIN(created_at) as min_at, MAX(created_at) as max_at')
            ->first();

        $min = $bounds?->min_at ? Carbon::parse($bounds->min_at) : $now;
        $max = $bounds?->max_at ? Carbon::parse($bounds->max_at) : $now;

        return self::available($min, $max, $now);
    }

    /**
     * @return list<string>
     */
    public static function available(CarbonInterface $min, CarbonInterface $max, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $current = self::fromDate($now);

        if ($min->gt($max)) {
            [$min, $max] = [$max, $min];
        }

        $periods = [];
        $cursor = self::fromDate($min);
        $last = self::fromDate($max);

        while (true) {
            $periods[] = $cursor;

            if ($cursor === $last) {
                break;
            }

            $cursor = self::shift($cursor, 1);

            if (count($periods) > 40) {
                break;
            }
        }

        if (! in_array($current, $periods, true)) {
            $periods[] = $current;
        }

        rsort($periods, SORT_STRING);

        return array_values(array_unique($periods));
    }

    /**
     * @return array{periodos: list<string>, current: string}
     */
    public static function filterOptions(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();

        return Cache::remember(
            'convenio_semester_filter_options',
            now()->addMinutes(5),
            fn (): array => [
                'periodos' => self::availableFromTracking($now),
                'current' => self::fromDate($now),
            ],
        );
    }

    private static function shift(string $periodo, int $delta): string
    {
        $year = (int) substr($periodo, 0, 4);
        $semester = (int) substr($periodo, 4, 1);
        $index = ($year * 2) + ($semester - 1) + $delta;
        $newYear = intdiv($index, 2);
        $newSemester = ($index % 2) + 1;

        return $newYear.$newSemester;
    }
}
