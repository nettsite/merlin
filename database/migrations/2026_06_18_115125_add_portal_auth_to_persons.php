<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table): void {
            $table->string('password')->nullable()->after('direct_line');
            $table->rememberToken()->after('password');
        });
    }
};
