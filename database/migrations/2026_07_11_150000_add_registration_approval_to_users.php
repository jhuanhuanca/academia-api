<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('is_active');
            // pending|approved|rejected — los usuarios existentes quedan approved
            $table->string('approval_token', 64)->nullable()->unique()->after('approval_status');
            $table->timestamp('approval_token_expires_at')->nullable()->after('approval_token');
            $table->timestamp('approved_at')->nullable()->after('approval_token_expires_at');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'approval_status',
                'approval_token',
                'approval_token_expires_at',
                'approved_at',
                'rejected_at',
            ]);
        });
    }
};
