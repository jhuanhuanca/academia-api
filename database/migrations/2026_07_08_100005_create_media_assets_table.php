<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 255);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'disk']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
