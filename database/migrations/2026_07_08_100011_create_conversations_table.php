<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->foreignId('whatsapp_instance_id')->constrained('whatsapp_instances')->restrictOnDelete();
            $table->foreignId('flow_id')->constrained('flows')->restrictOnDelete();
            $table->unsignedInteger('flow_version')->default(1);
            $table->unsignedBigInteger('current_node_id')->nullable();
            $table->string('status', 30)->default('open');
            // open|waiting_input|waiting_payment|handed_off|closed
            $table->json('context')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['lead_id', 'status']);
            $table->foreign('current_node_id')->references('id')->on('flow_nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
