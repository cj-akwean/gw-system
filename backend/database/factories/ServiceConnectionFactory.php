<?php

namespace Database\Factories;

use App\Models\Barangay;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceConnectionFactory extends Factory
{
    protected $model = ServiceConnection::class;

    protected static int $counter = 0;

    public function definition(): array
    {
        static::$counter++;

        return [
            'account_number' => 'GW-' . str_pad((string) static::$counter, 5, '0', STR_PAD_LEFT),
            'meter_number' => 'MTR-' . str_pad((string) static::$counter, 5, '0', STR_PAD_LEFT),
            'registered_name' => fake()->name(),
            'barangay_id' => Barangay::inRandomOrder()->first()?->id ?? Barangay::factory(),
            'address' => fake()->streetAddress(),
            'status' => 'active',
            'connection_date' => fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d'),
            'rate_schedule_id' => RateSchedule::factory(),
        ];
    }
}
