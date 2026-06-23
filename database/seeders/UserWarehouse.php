<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserWarehouse extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Map users to warehouses
        DB::table('user_warehouse')->insert([
            // Super admin -> warehouse 1 (primary)
            [ 'user_id' => 1, 'warehouse_id' => 1 ],
            // Admin -> warehouse 1
            [ 'user_id' => 2, 'warehouse_id' => 1 ],

            // Branch 1 (Kamra Road) users -> warehouse 1
            [ 'user_id' => 7, 'warehouse_id' => 1 ],
            [ 'user_id' => 8, 'warehouse_id' => 1 ],
            [ 'user_id' => 9, 'warehouse_id' => 1 ],
            [ 'user_id' => 10, 'warehouse_id' => 1 ],

            // Branch 2 (Madani Chowk) users -> warehouse 2
            [ 'user_id' => 11, 'warehouse_id' => 2 ],
            [ 'user_id' => 12, 'warehouse_id' => 2 ],
            [ 'user_id' => 13, 'warehouse_id' => 2 ],
            [ 'user_id' => 14, 'warehouse_id' => 2 ],

            // Branch 3 (Kamra Branch) users -> warehouse 3
            [ 'user_id' => 15, 'warehouse_id' => 3 ],
            [ 'user_id' => 16, 'warehouse_id' => 3 ],
            [ 'user_id' => 17, 'warehouse_id' => 3 ],
            [ 'user_id' => 18, 'warehouse_id' => 3 ],

            // Branch 4 (Hazro) users -> warehouse 4
            [ 'user_id' => 19, 'warehouse_id' => 4 ],
            [ 'user_id' => 20, 'warehouse_id' => 4 ],
            [ 'user_id' => 21, 'warehouse_id' => 4 ],
            [ 'user_id' => 22, 'warehouse_id' => 4 ],
        ]);
    }
}
