<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('evolution_instance', 100);
            $table->text('evolution_apikey')->nullable();
            $table->string('integration', 20)->default('baileys'); // baileys|business
            $table->string('phone_e164', 20)->nullable();
            $table->string('status', 20)->default('disconnected'); // disconnected|connecting|open|close
            $table->string('webhook_secret', 64);
            $table->json('meta')->nullable();
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'evolution_instance']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instances');
    }
};
