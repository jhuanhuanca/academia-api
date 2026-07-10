<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('draft'); // draft|published|archived
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('start_node_id')->nullable(); // sin FK (evita ciclo)
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flows');
    }
};
