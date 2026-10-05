<?php

namespace Database\Factories;

use App\Models\ArrCacheDeparture;
use App\Models\ArrIntegration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrCacheDeparture>
 */
class ArrCacheDepartureFactory extends Factory
{
    protected $model = ArrCacheDeparture::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'arr_integration_id' => ArrIntegration::factory()->radarr(),
            'arr_movie_id' => fake()->unique()->numberBetween(1, 100000),
            'tmdb_id' => fake()->unique()->numberBetween(1, 1000000),
            'left_at' => now(),
        ];
    }
}
