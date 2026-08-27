<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $gstId = DB::table('taxes')->where('code', 'GST')->value('id');
        $rbId = DB::table('tax_price_types')->where('code', 'company_rb_price')->value('id');
        $costId = DB::table('tax_price_types')->where('code', 'cost')->value('id');

        if (! $gstId || ! $costId) {
            return;
        }

        if ($rbId) {
            DB::table('tax_price_type')->where(['tax_id' => $gstId, 'tax_price_type_id' => $rbId])->delete();
        }
        DB::table('tax_price_type')->updateOrInsert(['tax_id' => $gstId, 'tax_price_type_id' => $costId]);
    }

    public function down(): void
    {
        $gstId = DB::table('taxes')->where('code', 'GST')->value('id');
        $rbId = DB::table('tax_price_types')->where('code', 'company_rb_price')->value('id');
        $costId = DB::table('tax_price_types')->where('code', 'cost')->value('id');

        if (! $gstId || ! $rbId) {
            return;
        }

        if ($costId) {
            DB::table('tax_price_type')->where(['tax_id' => $gstId, 'tax_price_type_id' => $costId])->delete();
        }
        DB::table('tax_price_type')->updateOrInsert(['tax_id' => $gstId, 'tax_price_type_id' => $rbId]);
    }
};
