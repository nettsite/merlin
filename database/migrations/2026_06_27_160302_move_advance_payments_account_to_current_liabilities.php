<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $liabilitiesGroupId = DB::table('account_groups')->where('code', '20')->value('id');

        if (! $liabilitiesGroupId) {
            return;
        }

        // Update existing 1300 → 2400 under Current Liabilities.
        $updated = DB::table('accounts')
            ->where('code', '1300')
            ->update([
                'code' => '2400',
                'account_group_id' => $liabilitiesGroupId,
            ]);

        // If 1300 never existed, create 2400 fresh.
        if ($updated === 0 && ! DB::table('accounts')->where('code', '2400')->exists()) {
            DB::table('accounts')->insert([
                'id' => Str::uuid()->toString(),
                'account_group_id' => $liabilitiesGroupId,
                'code' => '2400',
                'name' => 'Over and Advance Payments',
                'is_system' => true,
                'is_active' => true,
                'allow_direct_posting' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
