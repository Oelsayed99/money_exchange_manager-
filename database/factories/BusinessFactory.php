<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Business> */
final class BusinessFactory extends Factory
{
    protected $model = Business::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'locale' => 'en',
        ];
    }
}
