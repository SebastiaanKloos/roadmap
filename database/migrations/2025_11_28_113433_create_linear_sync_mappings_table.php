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
        Schema::create('linear_sync_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->string('linear_id')->unique()->comment('Linear issue UUID');
            $table->string('linear_identifier')->nullable()->comment('Linear issue identifier (e.g., PROJ-123)');
            $table->string('linear_team_key')->nullable()->comment('Linear team name for filtering');
            $table->json('linear_labels')->nullable()->comment('Cache of Linear labels for filtering');
            $table->timestamp('last_synced_at')->nullable();
            $table->enum('sync_status', ['synced', 'pending', 'error'])->default('pending');
            $table->enum('sync_direction', ['to_linear', 'from_linear', 'both'])->default('both');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable()->comment('Additional Linear metadata');
            $table->timestamps();

            $table->index(['linear_id', 'linear_team_key']);
            $table->index('sync_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('linear_sync_mappings');
    }
};
