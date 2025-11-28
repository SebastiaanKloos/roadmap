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
        Schema::create('linear_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('linear_id')->nullable();
            $table->enum('action', ['create', 'update', 'delete', 'sync', 'error'])->default('sync');
            $table->enum('direction', ['to_linear', 'from_linear'])->nullable();
            $table->enum('status', ['success', 'failed', 'skipped'])->default('success');
            $table->text('message')->nullable();
            $table->json('payload')->nullable()->comment('Request/response data');
            $table->text('error_details')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'created_at']);
            $table->index(['linear_id', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('linear_sync_logs');
    }
};
