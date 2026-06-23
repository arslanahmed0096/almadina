<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Insert some stuff
        DB::table('settings')->insert(
            [
                'id' => 1,
                'email' => 'admin@example.com',
                'currency_id' => 1,
                'client_id' => 1,
                'sms_gateway' => 1,
                'point_to_amount_rate' => 1,
                'is_invoice_footer' => 0,
                'invoice_footer' => null,
                'warehouse_id' => null,
                'CompanyName' => 'Al Madina Electronics',
                'CompanyPhone' => '6315996770',
                'CompanyAdress' => '3618 Abia Martin Drive',
                'footer' => 'Al Madina Inventory with POS developed By Nasir Mehmood',
                'developed_by' => 'Nasir Mehmood',
                'logo' => 'logo-default.png',
                'app_name' => 'Al Madina | Inventory with POS',
                'page_title_suffix' => 'Inventory with POS',
                'favicon' => 'favicon.ico',
                'default_language' => 'en',
                'quotation_with_stock' => 1,
                'show_language' => 1,
                'default_tax' => 0,
            ]

        );
    }
}
