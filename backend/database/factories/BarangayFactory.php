<?php

namespace Database\Factories;

use App\Models\Barangay;
use Illuminate\Database\Eloquent\Factories\Factory;

class BarangayFactory extends Factory
{
    protected $model = Barangay::class;

    protected static array $barangays = [
        'Poblacion',
        'Mauraro',
        'San Rafael',
        'Masarawag',
        'Maipon',
        'Travesia',
        'San Francisco',
        'Quibongbongan',
        'Calzada',
        'Quitago',
        'Morera',
        'Muladbucad Grande',
        'Binogsacan Lower',
        'Maguiron',
        'Lomacao',
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(static::$barangays),
        ];
    }
}
