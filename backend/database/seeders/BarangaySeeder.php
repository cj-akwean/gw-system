<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $barangays = [
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

        foreach ($barangays as $name) {
            Barangay::firstOrCreate(['name' => $name]);
        }
    }
}
