<?php

class KnmiStationCatalog {
    public const DEFAULT_STATION = 260;

    private const STATIONS = [
        235 => 'De Kooy Airport',
        240 => 'Schiphol Airport',
        260 => 'De Bilt',
        270 => 'Leeuwarden Airport',
        275 => 'Deelen Airport',
        277 => 'Lauwersoog',
        280 => 'Groningen Airport Eelde',
        286 => 'Nieuw Beerta',
        290 => 'Twenthe Airport',
        310 => 'Vlissingen',
        319 => 'Westdorpe',
        344 => 'Rotterdam Airport',
        348 => 'Cabauw',
        350 => 'Gilze-Rijen Airport',
        370 => 'Eindhoven Airport',
        380 => 'Maastricht Airport'
    ];

    public static function all(): array {
        $stations = [];

        foreach (self::STATIONS as $id => $name) {
            $stations[] = self::formatStation((int)$id, $name);
        }

        return $stations;
    }

    public static function ids(): array {
        return array_keys(self::STATIONS);
    }

    public static function exists($station): bool {
        return isset(self::STATIONS[(int)$station]);
    }

    public static function name($station): ?string {
        return self::STATIONS[(int)$station] ?? null;
    }

    public static function default(): array {
        return self::formatStation(self::DEFAULT_STATION, self::STATIONS[self::DEFAULT_STATION]);
    }

    public static function get($station): ?array {
        $station = (int)$station;
        if (!isset(self::STATIONS[$station])) {
            return null;
        }

        return self::formatStation($station, self::STATIONS[$station]);
    }

    private static function formatStation(int $id, string $name): array {
        return [
            'id' => $id,
            'name' => $name,
            'label' => $name . ' (' . $id . ')',
            'is_default' => $id === self::DEFAULT_STATION
        ];
    }
}
