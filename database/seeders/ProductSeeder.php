<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = \Faker\Factory::create();

        // Create 10 flash sale products with random data
        for ($i = 0; $i < 10; $i++) {
            Product::create([
                'name' => $faker->words(3, true) . ' - Flash Sale',
                'description' => $faker->sentence(15),
                'price' => $faker->randomFloat(2, 99, 1999),
                'stock' => $faker->numberBetween(10, 200),
            ]);
        }
    }
}
