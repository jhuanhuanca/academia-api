<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('method', 30)->default('whatsapp_message'); // whatsapp_message|email|manual
            $table->string('destination', 190)->nullable();
            $table->json('payload_sent')->nullable();
            $table->string('status', 20)->default('pending'); // pending|sent|failed
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
