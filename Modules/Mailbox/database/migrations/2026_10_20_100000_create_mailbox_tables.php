<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('kind', 10); // local | imap | google
            $t->string('email', 190);
            $t->string('display_name', 120)->nullable();
            $t->string('provider', 20)->nullable(); // preset key
            $t->string('imap_host', 190)->nullable();
            $t->unsignedSmallInteger('imap_port')->nullable();
            $t->string('imap_security', 5)->nullable(); // ssl | tls | none
            $t->string('smtp_host', 190)->nullable();
            $t->unsignedSmallInteger('smtp_port')->nullable();
            $t->string('smtp_security', 5)->nullable();
            $t->string('username', 190)->nullable();
            $t->text('secret')->nullable(); // encrypted password (imap) - google tokens live in mail_settings
            $t->boolean('is_default')->default(false);
            $t->json('sync_state')->nullable(); // per folder: uidvalidity + last uid
            $t->timestamp('last_sync_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'email']);
            $t->index('email');
        });

        Schema::create('mail_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $t->string('folder', 12)->default('inbox'); // inbox sent drafts archive spam trash
            $t->string('status', 10)->default('received'); // received draft sending sent failed
            $t->string('message_id', 250)->nullable();
            $t->string('in_reply_to', 250)->nullable();
            $t->string('thread_key', 64)->nullable();
            $t->string('from_email', 190)->nullable();
            $t->string('from_name', 190)->nullable();
            $t->json('to')->nullable();
            $t->json('cc')->nullable();
            $t->json('bcc')->nullable();
            $t->string('subject', 300)->nullable();
            $t->string('snippet', 220)->nullable();
            $t->longText('body_text')->nullable();
            $t->longText('body_html')->nullable();
            $t->boolean('has_attachments')->default(false);
            $t->unsignedInteger('size')->default(0); // bytes counted against the mailbox
            $t->boolean('seen')->default(false);
            $t->boolean('starred')->default(false);
            $t->string('remote_folder', 120)->nullable();
            $t->unsignedBigInteger('remote_uid')->nullable();
            $t->string('drive_file_id', 120)->nullable(); // archived to the user's Google Drive
            $t->string('error', 300)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'folder', 'sent_at']);
            $t->index(['user_id', 'thread_key']);
            $t->index(['account_id', 'remote_folder', 'remote_uid']);
        });

        Schema::create('mail_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $t->string('filename', 190);
            $t->string('mime', 120)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->string('path')->nullable(); // private disk; null when archived to Drive
            $t->timestamps();
        });

        Schema::create('mail_labels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 60);
            $t->string('color', 9)->default('#6366f1');
            $t->timestamps();
            $t->unique(['user_id', 'name']);
        });

        Schema::create('mail_label_message', function (Blueprint $t) {
            $t->foreignId('label_id')->constrained('mail_labels')->cascadeOnDelete();
            $t->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $t->primary(['label_id', 'message_id']);
        });

        Schema::create('mail_filters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('field', 10); // from | to | subject
            $t->string('contains', 190);
            $t->string('action', 10); // label | folder | star | read
            $t->string('value', 60)->nullable();
            $t->timestamps();
        });

        Schema::create('mail_contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 190)->nullable();
            $t->string('email', 190);
            $t->string('phone', 40)->nullable();
            $t->string('source', 10)->default('manual'); // manual | google | sent
            $t->timestamps();
            $t->unique(['user_id', 'email']);
        });

        Schema::create('mail_settings', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->text('signature')->nullable();
            $t->boolean('vacation_on')->default(false);
            $t->string('vacation_subject', 190)->nullable();
            $t->text('vacation_body')->nullable();
            $t->date('vacation_until')->nullable();
            $t->unsignedInteger('quota_mb')->nullable(); // from the imsahiraf Insights package; null = free size
            $t->string('plan', 60)->nullable();
            $t->string('google_email', 190)->nullable();
            $t->text('google_token')->nullable(); // encrypted json: access_token, refresh_token, expires_at
            $t->boolean('drive_archive')->default(true);
            $t->boolean('contacts_sync')->default(true);
            $t->string('drive_folder_id', 120)->nullable();
            $t->timestamp('google_synced_at')->nullable();
            $t->string('google_error', 300)->nullable();
            $t->json('vacation_sent')->nullable(); // sender => date, one auto-reply per sender per 4 days
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mail_settings', 'mail_contacts', 'mail_filters', 'mail_label_message', 'mail_labels', 'mail_attachments', 'mail_messages', 'mail_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
