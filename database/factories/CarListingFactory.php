<?php

namespace Database\Factories;

use App\Models\CarListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarListing>
 */
class CarListingFactory extends Factory
{
    protected $model = CarListing::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fingerprint' => $this->faker->unique()->sha1(),
            'title' => $this->faker->randomElement(['BMW X5', 'Audi A4', 'Mercedes-Benz GLA']).' '.$this->faker->bothify('?.#'),
            'description' => $this->faker->sentence(),
            'year' => $this->faker->numberBetween(2000, 2024),
            'price' => $this->faker->numberBetween(2000, 80000),
            'mileage' => $this->faker->numberBetween(0, 350000),
            'fuel_type' => $this->faker->randomElement(['Petrol', 'Diesel', 'Hybrid', 'Electric']),
            'transmission' => $this->faker->randomElement(['Automatic', 'Manual']),
            'location' => $this->faker->randomElement(['София', 'Пловдив', 'Варна']),
            'image_url' => 'https://example.com/'.$this->faker->uuid().'.webp',
            'source_urls' => ['auto.bg' => 'https://www.auto.bg/obiava/'.$this->faker->randomNumber(8)],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
