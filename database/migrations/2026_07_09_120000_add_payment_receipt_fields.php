<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('receipt_media_asset_id')
                ->nullable()
                ->after('qr_media_asset_id')
                ->constrained('media_assets')
                ->nullOnDelete();
            $table->string('confirmation_token', 80)->nullable()->unique()->after('paid_at');
            $table->timestamp('confirmation_expires_at')->nullable()->after('confirmation_token');
            $table->timestamp('receipt_submitted_at')->nullable()->after('confirmation_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['receipt_media_asset_id']);
            $table->dropColumn([
                'receipt_media_asset_id',
                'confirmation_token',
                'confirmation_expires_at',
                'receipt_submitted_at',
            ]);
        });
    }
};
