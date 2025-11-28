<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('linear_id')->nullable()->after('issue_number')->comment('Linear issue UUID');
            $table->string('linear_url')->nullable()->after('linear_id')->comment('Direct URL to Linear issue');

            $table->index('linear_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['linear_id', 'linear_url']);
        });
    }
};
