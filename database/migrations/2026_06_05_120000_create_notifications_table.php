<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard `notifications` table — required by Filament's DATABASE
 * notification channel, which import/backup/etc. use to tell the operator a
 * background job finished. It was never present in this project, so every such
 * notification job failed with "Table 'notifications' doesn't exist", which is
 * why bulk imports appeared to "start" but never reported completion
 * (Feedback 1 — import bug). Cross-engine + idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            // UUID string PK — Laravel's DatabaseNotification uses a UUID id.
            $table->uuid('id')->primary();
            $table->string('type');
            // Polymorphic owner of the notification (the notifiable User).
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
