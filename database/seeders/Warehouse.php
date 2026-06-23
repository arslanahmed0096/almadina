<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Warehouse extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Insert warehouse branches
        DB::table('warehouses')->insert(
            [
                [
                    'id' => 1,
                    'name' => 'Al Madina Branch Kamra Road',
                    'city' => 'Kamra Road',
                    'mobile' => null,
                    'zip' => null,
                    'email' => null,
                    'country' => null,
                ],
                [
                    'id' => 2,
                    'name' => 'Al Madina Branch Madani Chowk',
                    'city' => 'Madani Chowk',
                    'mobile' => null,
                    'zip' => null,
                    'email' => null,
                    'country' => null,
                ],
                [
                    'id' => 3,
                    'name' => 'Al Madina Kamra Branch',
                    'city' => 'Kamra',
                    'mobile' => null,
                    'zip' => null,
                    'email' => null,
                    'country' => null,
                ],
                [
                    'id' => 4,
                    'name' => 'Al Madina Hazro Branch',
                    'city' => 'Hazro',
                    'mobile' => null,
                    'zip' => null,
                    'email' => null,
                    'country' => null,
                ],
            ]
        );
    }
}
