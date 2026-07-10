<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('provider', 40)->default('manual_qr');
            $table->string('external_id', 120)->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->string('status', 20)->default('created');
            // created|pending|paid|failed|expired|cancelled
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('USD');
            $table->text('qr_payload')->nullable();
            $table->foreignId('qr_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index(['sale_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
