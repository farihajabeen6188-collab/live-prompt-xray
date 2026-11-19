<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Fictional, deterministic forecasts: the same city and day always give the same weather.
 */
final class WeatherService
{
    private const array CONDITIONS = ['sunny', 'partly cloudy', 'cloudy', 'light rain', 'showers', 'windy'];

    /**
     * @return array{city: string, day: string, condition: string, temperatureC: int, chanceOfRainPercent: int, source: string}
     */
    public function forecast(string $city, string $day): array
    {
        $city = ucwords(strtolower(trim($city)));
        $day = strtolower(trim($day));
        $seed = crc32($city.'|'.$day);
        $condition = self::CONDITIONS[$seed % \count(self::CONDITIONS)];
        $rainy = \in_array($condition, ['light rain', 'showers'], true);

        return [
            'city' => $city,
            'day' => $day,
            'condition' => $condition,
            'temperatureC' => 8 + ($seed >> 4) % 15,
            'chanceOfRainPercent' => $rainy ? 60 + ($seed >> 8) % 35 : ($seed >> 8) % 25,
            'source' => 'demo forecast (fictional)',
        ];
    }
}
