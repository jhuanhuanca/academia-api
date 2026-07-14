<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->string('meta_phone_number_id', 64)->nullable()->after('integration');
            $table->string('meta_waba_id', 64)->nullable()->after('meta_phone_number_id');
            $table->text('meta_access_token')->nullable()->after('meta_waba_id');
            $table->index(['integration', 'meta_phone_number_id'], 'wa_meta_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_instances', function (Blueprint $table) {
            $table->dropIndex('wa_meta_phone_idx');
            $table->dropColumn([
                'meta_phone_number_id',
                'meta_waba_id',
                'meta_access_token',
            ]);
        });
    }
};
