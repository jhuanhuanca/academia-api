<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->foreignId('from_node_id')->constrained('flow_nodes')->cascadeOnDelete();
            $table->foreignId('to_node_id')->constrained('flow_nodes')->cascadeOnDelete();
            $table->string('trigger_type', 40)->default('default');
            // default|button|list|condition_true|condition_false|ai_transition|payment_paid|payment_failed|payment_expired|timeout
            $table->string('trigger_key', 80)->default('');
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->unique(
                ['flow_id', 'from_node_id', 'trigger_type', 'trigger_key'],
                'flow_edges_unique_trigger'
            );
            $table->index(['flow_id', 'from_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_edges');
    }
};
