<?php

namespace Database\Factories;

use App\Models\GalleryImage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryImage>
 */
class GalleryImageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'caption' => fake()->sentence(3),
            'path' => 'gallery/'.fake()->uuid().'.jpg',
            'source' => 'upload',
        ];
    }

    public function generated(): static
    {
        return $this->state([
            'source' => 'generated',
            'caption' => 'nature 800×600',
            'width' => 800,
            'height' => 600,
        ]);
    }
}
