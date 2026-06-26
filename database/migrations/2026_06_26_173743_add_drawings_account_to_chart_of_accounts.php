<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $groupId = DB::table('account_groups')->where('name', "Owner's Equity")->value('id');

        if (! $groupId) {
            return;
        }

        if (DB::table('accounts')->where('code', '3300')->exists()) {
            return;
        }

        DB::table('accounts')->insert([
            'id' => Str::orderedUuid(),
            'account_group_id' => $groupId,
            'code' => '3300',
            'name' => 'Drawings',
            'is_active' => true,
            'allow_direct_posting' => true,
            'is_system' => false,
            'sort_order' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
