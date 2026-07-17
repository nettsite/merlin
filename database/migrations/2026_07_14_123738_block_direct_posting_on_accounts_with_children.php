<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix: any account that already has sub-accounts but is still
     * flagged directly postable (e.g. 1100 Accounts Receivable, 2000
     * Accounts Payable, both created before their sub-accounts existed)
     * must be corrected. Going forward, Account::booted() prevents this
     * state from recurring.
     */
    public function up(): void
    {
        DB::table('accounts')
            ->whereIn('id', DB::table('accounts')->whereNotNull('parent_id')->distinct()->pluck('parent_id'))
            ->update(['allow_direct_posting' => false]);
    }
};
