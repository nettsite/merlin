<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded by Phase 1a metadata convention.
        // Relationship-specific data (payable/receivable accounts, payment terms)
        // lives in party_relationships.metadata, not as typed columns.
    }

    public function down(): void
    {
        //
    }
};
