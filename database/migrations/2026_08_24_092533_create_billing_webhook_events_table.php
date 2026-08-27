<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('external_id', 191);
            $table->string('event_type', 120);
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->string('status', 24);
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('received_at');
            $table->timestamp('processing_until')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'processing_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
    }
};
