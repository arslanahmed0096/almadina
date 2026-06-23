<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Insert roles
        DB::table('roles')->insert(
            [
                [
                    'id' => 1,
                    'name' => 'Super Admin',
                    'label' => 'Super Admin',
                    'status' => 1,
                    'description' => 'Super Admin',
                ],
                [
                    'id' => 2,
                    'name' => 'Admin',
                    'label' => 'Admin',
                    'status' => 1,
                    'description' => 'Admin',
                ],
                [
                    'id' => 3,
                    'name' => 'Branch Manager',
                    'label' => 'Branch Manager',
                    'status' => 1,
                    'description' => 'Branch Manager',
                ],
                [
                    'id' => 4,
                    'name' => 'Accountant',
                    'label' => 'Accountant',
                    'status' => 1,
                    'description' => 'Accountant',
                ],
                [
                    'id' => 5,
                    'name' => 'Salesman',
                    'label' => 'Salesman',
                    'status' => 1,
                    'description' => 'Salesman',
                ],
                [
                    'id' => 6,
                    'name' => 'Cashier',
                    'label' => 'Cashier',
                    'status' => 1,
                    'description' => 'Cashier',
                ],
            ]
        );
    }
}
