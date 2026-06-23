<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Map each seeded user to their role via the pivot table
        DB::table('role_user')->insert([
            [ 'id' => 1, 'user_id' => 1, 'role_id' => 1 ],
            [ 'id' => 2, 'user_id' => 2, 'role_id' => 2 ],
            [ 'id' => 3, 'user_id' => 3, 'role_id' => 3 ],
            [ 'id' => 4, 'user_id' => 4, 'role_id' => 4 ],
            [ 'id' => 5, 'user_id' => 5, 'role_id' => 5 ],
            [ 'id' => 6, 'user_id' => 6, 'role_id' => 6 ],
            [ 'id' => 7, 'user_id' => 7, 'role_id' => 3 ],
            [ 'id' => 8, 'user_id' => 8, 'role_id' => 4 ],
            [ 'id' => 9, 'user_id' => 9, 'role_id' => 5 ],
            [ 'id' => 10, 'user_id' => 10, 'role_id' => 6 ],
            [ 'id' => 11, 'user_id' => 11, 'role_id' => 3 ],
            [ 'id' => 12, 'user_id' => 12, 'role_id' => 4 ],
            [ 'id' => 13, 'user_id' => 13, 'role_id' => 5 ],
            [ 'id' => 14, 'user_id' => 14, 'role_id' => 6 ],
            [ 'id' => 15, 'user_id' => 15, 'role_id' => 3 ],
            [ 'id' => 16, 'user_id' => 16, 'role_id' => 4 ],
            [ 'id' => 17, 'user_id' => 17, 'role_id' => 5 ],
            [ 'id' => 18, 'user_id' => 18, 'role_id' => 6 ],
            [ 'id' => 19, 'user_id' => 19, 'role_id' => 3 ],
            [ 'id' => 20, 'user_id' => 20, 'role_id' => 4 ],
            [ 'id' => 21, 'user_id' => 21, 'role_id' => 5 ],
            [ 'id' => 22, 'user_id' => 22, 'role_id' => 6 ],
        ]);
    }
}
