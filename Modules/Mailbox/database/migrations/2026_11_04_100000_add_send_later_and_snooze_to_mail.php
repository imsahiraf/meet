<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// send later, undo send, snooze, conversation view (Mail 1.4.3) and contact groups (1.4.4)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $t) {
            if (!Schema::hasColumn('mail_messages', 'send_at')) {
                $t->timestamp('send_at')->nullable()->index(); // scheduled / undo window: sent by mailbox:due at this time
            }
            if (!Schema::hasColumn('mail_messages', 'snooze_until')) {
                $t->timestamp('snooze_until')->nullable()->index(); // snoozed: back in the inbox at this time
            }
            if (!Schema::hasColumn('mail_messages', 'reply_ref_id')) {
                $t->unsignedBigInteger('reply_ref_id')->nullable(); // the message a scheduled reply answers
            }
        });
        if (!Schema::hasTable('mail_contact_groups')) { // a name for several addresses: added to To in one go
            Schema::create('mail_contact_groups', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('name', 60);
                $t->text('emails');
                $t->timestamps();
                $t->unique(['user_id', 'name']);
            });
        }
        if (!Schema::hasTable('mail_delegates')) { // a colleague who may read and send from my mailbox (Mail settings > Share my mailbox)
            Schema::create('mail_delegates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
                $t->timestamps();
                $t->unique(['owner_id', 'delegate_id']);
            });
        }
        Schema::table('mail_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('mail_settings', 'undo_seconds')) {
                $t->unsignedSmallInteger('undo_seconds')->default(0); // 0 = send at once
            }
            if (!Schema::hasColumn('mail_settings', 'conversations')) {
                $t->boolean('conversations')->default(true); // the list shows a conversation once, with its count
            }
        });
    }

    public function down(): void
    {
        // columns stay (data safety)
    }
};
