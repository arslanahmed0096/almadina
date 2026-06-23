<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Insert currency
        DB::table('currencies')->insert(
            [
                'id' => 1,
                'code' => 'PKR',
                'name' => 'Pakistani Rupee',
                'symbol' => 'Rs',
            ]

        );
    }
}
