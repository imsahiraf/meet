<?php

namespace Tests\Feature\Mailbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Models\MailSetting;
use Modules\Mailbox\Services\DriveArchive;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mailbox.domain' => 'mail.example.com', 'mailbox.free_quota_mb' => 30, 'mail.default' => 'array']);
        Storage::fake('local');
    }

    private function user(string $name = 'Asha'): User
    {
        return User::factory()->create(['full_name' => $name, 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function raw(string $to, string $subject = 'Hello', string $from = 'Ravi <ravi@example.com>', string $extra = ''): string
    {
        $b = 'b' . uniqid();

        return "From: $from\r\nTo: $to\r\nSubject: $subject\r\nMessage-ID: <" . uniqid() . "@example.com>\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\n$extra"
            . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p onclick=\"x()\">Hi <b>there</b></p><script>alert(1)</script>\r\n"
            . "--$b\r\nContent-Type: text/plain; name=\"notes.txt\"\r\nContent-Disposition: attachment; filename=\"notes.txt\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('attached text') . "\r\n--$b--\r\n";
    }

    private function pipe(string $raw): void
    {
        $f = tempnam(sys_get_temp_dir(), 'mail');
        file_put_contents($f, $raw);
        $this->artisan('mailbox:pipe', ['--file' => $f])->assertSuccessful();
        @unlink($f);
    }

    public function test_address_delivery_reading_and_actions(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get('/mail')->assertOk()->assertSee('Welcome to Meet Mail');
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'admin'])->assertSessionHasErrors('local');
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha.k'])->assertRedirect('/mail');
        $this->assertDatabaseHas('mail_accounts', ['email' => 'asha.k.user@mail.example.com', 'kind' => 'local', 'is_default' => true]);
        $this->actingAs($this->user('Other'))->post('/mail/settings/address', ['local' => 'asha.k'])->assertSessionHasErrors('local');

        $this->pipe($this->raw('asha.k.user@mail.example.com', 'Quarterly report'));
        $this->pipe($this->raw('nobody@mail.example.com'));
        $m = MailMessage::firstOrFail();
        $this->assertSame(['Quarterly report', 'ravi@example.com', 'inbox', false, true], [$m->subject, $m->from_email, $m->folder, $m->seen, $m->has_attachments]);
        $this->assertSame(1, MailMessage::count());

        $this->actingAs($u)->get('/mail')->assertOk()->assertSee('Quarterly report')->assertSee('Ravi');
        $page = $this->actingAs($u)->get("/mail/m/{$m->id}")->assertOk()->assertSee('sandbox="allow-popups', false)->assertSee('notes.txt');
        $this->assertStringNotContainsString('allow-scripts', $page->getContent());
        $this->assertTrue($m->fresh()->seen);
        $att = $m->attachments()->first();
        $this->assertSame('attached text', $this->actingAs($u)->get("/mail/attachment/{$att->id}")->assertOk()->streamedContent());

        // another user sees nothing of it
        $other = $this->user('Mallory');
        $this->actingAs($other)->get("/mail/m/{$m->id}")->assertNotFound();
        $this->actingAs($other)->get("/mail/attachment/{$att->id}")->assertNotFound();

        $this->actingAs($u)->post("/mail/m/{$m->id}/do", ['action' => 'star'])->assertRedirect();
        $this->actingAs($u)->get('/mail?folder=starred')->assertSee('Quarterly report');
        $this->actingAs($u)->post('/mail/bulk', ['ids' => [$m->id], 'action' => 'delete']);
        $this->assertSame('trash', $m->fresh()->folder);
        $this->actingAs($u)->post('/mail/trash/empty')->assertRedirect();
        $this->assertSame(0, MailMessage::count());
        Storage::disk('local')->assertMissing($att->path);
    }

    public function test_alias_domain_reaches_the_same_mailbox_and_admins_see_the_server_card(): void
    {
        config(['mailbox.alias_domains' => 'example.net, mail.example.com', 'services.cpanel.token' => 'TOKEN123', 'services.cpanel.user' => 'zu']);
        putenv('HOME=' . sys_get_temp_dir());
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $this->pipe($this->raw('Asha <ASHA.user@example.net>', 'To the com address'));
        $this->pipe($this->raw('asha.user@other.com', 'Not ours'));
        $this->assertSame(['To the com address'], MailMessage::pluck('subject')->all());

        // plain users never see the server card or reach its button
        $this->actingAs($u)->get('/mail/settings')->assertOk()->assertDontSee('Mail server (admins)');
        $this->actingAs($u)->post('/mail/settings/server')->assertForbidden();

        $admin = $this->user('Root');
        \Modules\Admin\Models\Admin::create(['user_id' => $admin->id, 'admin_level' => 'super', 'is_super_admin' => true]);
        Http::fake(['*list_default_address*' => Http::response(['status' => 1, 'data' => []]), '*' => Http::response(['status' => 1, 'data' => null])]);
        $this->actingAs($admin)->post('/mail/settings/server')->assertRedirect()->assertSessionHas('status');
        $sets = collect(Http::recorded())->map(fn ($p) => $p[0]->url())->filter(fn ($u) => str_contains($u, 'set_default_address'))->values();
        $this->assertCount(2, $sets); // mail.example.com + example.net (the main domain is not repeated as an alias)
        $this->assertTrue($sets->contains(fn ($u) => str_contains($u, 'domain=example.net')));
        $this->actingAs($admin)->get('/mail/settings')->assertOk()->assertSee('Mail server (admins)')->assertSee('example.net')->assertSee('Working');
    }

    /** Mail Hub parity (2026-11-11): ask for a read receipt; the MDN that comes back is noted on the sent mail. */
    public function test_read_receipt_asked_and_noted(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $acc = MailAccount::where('user_id', $u->id)->firstOrFail();
        $this->actingAs($u)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'ravi@example.com', 'subject' => 'Contract', 'body' => '<p>Please read</p>', 'receipt' => 1])->assertSessionHasNoErrors();
        $sent = MailMessage::where('user_id', $u->id)->where('folder', 'sent')->firstOrFail();
        $this->assertSame('asked', $sent->send_meta['receipt'] ?? null);
        $this->actingAs($u)->get("/mail/m/{$sent->id}")->assertOk()->assertSee('Read receipt asked');

        $b = 'r' . uniqid();
        $mdn = "From: Ravi <ravi@example.com>\r\nTo: {$acc->email}\r\nSubject: Read: Contract\r\nMessage-ID: <" . uniqid() . "@example.com>\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/report; report-type=disposition-notification; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain\r\n\r\nYour message was displayed.\r\n"
            . "--$b\r\nContent-Type: message/disposition-notification\r\n\r\nFinal-Recipient: rfc822; ravi@example.com\r\nOriginal-Message-ID: <{$sent->message_id}>\r\nDisposition: manual-action/MDN-sent-manually; displayed\r\n--$b--\r\n";
        $this->pipe($mdn);
        $read = $sent->fresh()->send_meta['read'][0] ?? [];
        $this->assertSame('ravi@example.com', $read['by'] ?? null);
        $this->actingAs($u)->get("/mail/m/{$sent->id}")->assertOk()->assertSee('Read by ravi@example.com');
    }

    /** "Help me write" in compose (Mail Hub parity): buttons on the page; without the AI key a clear message, never a crash. */
    public function test_write_help_buttons_and_no_key_message(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $this->actingAs($u)->get('/mail/compose')->assertOk()->assertSee('data-write="polite"', false);
        config(['meetings.ai.key' => '']);
        $this->actingAs($u)->postJson('/mail/write-help', ['mode' => 'polite', 'html' => '<p>send the bill</p>'])->assertStatus(422)->assertJsonFragment(['message' => 'Writing help needs the AI key (MEET_AI_KEY). Ask your administrator.']);
        $this->actingAs($u)->postJson('/mail/write-help', ['mode' => 'shout', 'html' => 'x'])->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    public function test_send_later_undo_send_and_snooze(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $acc = MailAccount::where('user_id', $u->id)->firstOrFail();

        // send later: waits in Scheduled with its attachment, leaves when due
        $at = now()->addHours(2);
        $this->actingAs($u)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'ravi@example.com', 'subject' => 'Later', 'body' => '<p>Hi</p>', 'send_at' => $at->format('Y-m-d\\TH:i'),
            'files' => [UploadedFile::fake()->createWithContent('a.txt', 'file body')]])->assertRedirect('/mail?folder=scheduled');
        $q = MailMessage::where('folder', 'scheduled')->firstOrFail();
        $this->assertSame(['sending', 1], [$q->status, $q->attachments()->count()]);
        $this->artisan('mailbox:due')->assertSuccessful();
        $this->assertSame('scheduled', $q->fresh()->folder); // not yet
        $this->travel(3)->hours();
        $this->artisan('mailbox:due')->assertSuccessful();
        $this->assertNull(MailMessage::find($q->id));
        $sent = MailMessage::where('folder', 'sent')->where('subject', 'Later')->firstOrFail();
        $this->assertTrue($sent->has_attachments);
        $this->travelBack();

        // a time in the past is refused
        $this->actingAs($u)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'ravi@example.com', 'subject' => 'Past', 'send_at' => now()->subHour()->format('Y-m-d\\TH:i')])->assertSessionHasErrors('send_at');

        // undo send: the message waits, Undo makes it a draft again
        $this->actingAs($u)->post('/mail/settings', ['undo_seconds' => 10])->assertSessionHasNoErrors();
        $r = $this->actingAs($u)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'ravi@example.com', 'subject' => 'Oops', 'body' => 'x'])->assertRedirect('/mail')->assertSessionHas('undo');
        $id = session('undo');
        $this->actingAs($u)->get('/mail')->assertSee('Undo');
        $this->actingAs($u)->post("/mail/m/$id/do", ['action' => 'cancel'])->assertRedirect("/mail/compose?draft=$id");
        $this->assertSame(['drafts', 'draft'], [MailMessage::find($id)->folder, MailMessage::find($id)->status]);
        $this->travel(1)->minutes();
        $this->artisan('mailbox:due');
        $this->assertSame(0, MailMessage::where('folder', 'sent')->where('subject', 'Oops')->count());
        $this->travelBack();
        $this->actingAs($u)->post('/mail/settings', ['undo_seconds' => 99])->assertSessionHasErrors('undo_seconds');

        // snooze: out of the inbox, back unread at the time
        $this->pipe($this->raw('asha.user@mail.example.com', 'Read me later'));
        $m = MailMessage::where('subject', 'Read me later')->firstOrFail();
        $m->update(['seen' => true]);
        $this->actingAs($u)->get("/mail/m/{$m->id}")->assertSee('Snooze until');
        $this->actingAs($u)->post("/mail/m/{$m->id}/do", ['action' => 'snooze', 'value' => 'tomorrow', 'from' => 'inbox'])->assertRedirect();
        $this->assertSame('snoozed', $m->fresh()->folder);
        $this->actingAs($u)->get('/mail?folder=snoozed')->assertSee('Read me later');
        $this->travel(2)->days();
        $this->artisan('mailbox:due')->assertSuccessful();
        $this->assertSame(['inbox', false], [$m->fresh()->folder, $m->fresh()->seen]);
        $this->travelBack();
        // another user cannot cancel or snooze it
        $this->actingAs($this->user('Mallory'))->post("/mail/m/{$m->id}/do", ['action' => 'snooze', 'value' => 'later'])->assertNotFound();
    }

    public function test_rules_on_message_text_anywhere_and_attachments(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $this->actingAs($u)->post('/mail/settings/filters', ['field' => 'attachment', 'contains' => '*', 'action' => 'label', 'value' => 'Files'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post('/mail/settings/filters', ['field' => 'body', 'contains' => 'there', 'action' => 'star'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post('/mail/settings/filters', ['field' => 'any', 'contains' => 'lottery', 'action' => 'folder', 'value' => 'spam'])->assertSessionHasNoErrors();
        $this->actingAs($u)->post('/mail/settings/filters', ['field' => 'header', 'contains' => 'x', 'action' => 'star'])->assertSessionHasErrors('field');

        $this->pipe($this->raw('asha.user@mail.example.com', 'Report')); // has notes.txt and "Hi there" in the text
        $m = MailMessage::where('subject', 'Report')->firstOrFail();
        $this->assertTrue($m->starred);
        $this->assertSame(['Files'], $m->labels()->pluck('name')->all());
        $this->pipe($this->raw('asha.user@mail.example.com', 'You won the LOTTERY'));
        $this->assertSame('spam', MailMessage::where('subject', 'You won the LOTTERY')->value('folder'));
    }

    public function test_up_to_two_aliases_receive_and_send(): void
    {
        $u = $this->user();
        foreach (['asha', 'asha.work', 'asha.shop'] as $n) {
            $this->actingAs($u)->post('/mail/settings/address', ['local' => $n])->assertRedirect('/mail');
        }
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha.four'])->assertSessionHasErrors('local');
        $this->assertSame(1, MailAccount::where('user_id', $u->id)->where('is_default', true)->count());
        $this->pipe($this->raw('asha.work.user@mail.example.com', 'For the work address'));
        $this->actingAs($u)->get('/mail')->assertSee('For the work address');
        $this->actingAs($u)->get('/mail/compose')->assertSee('asha.shop.user@mail.example.com');
        $this->actingAs($u)->get('/mail/settings')->assertDontSee('Add another address (alias)');
    }

    public function test_conversation_view_shows_a_thread_once_with_its_count(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $this->pipe($this->raw('asha.user@mail.example.com', 'Plan A'));
        $this->pipe($this->raw('asha.user@mail.example.com', 'Re: Plan A'));
        $this->pipe($this->raw('asha.user@mail.example.com', 'Other topic'));
        MailMessage::whereIn('subject', ['Plan A', 'Re: Plan A'])->update(['thread_key' => 'tk-plan']);
        $page = $this->actingAs($u)->get('/mail')->assertOk()->assertSee('Re: Plan A')->assertSee('Other topic')->assertSee('(2)')->getContent();
        $this->assertStringNotContainsString('>Plan A<', str_replace(['⭐ ', "\n"], '', $page));
        // switched off: every message on its own line
        $this->actingAs($u)->post('/mail/settings', ['conversations' => 0])->assertSessionHasNoErrors();
        $this->actingAs($u)->get('/mail')->assertSee('Plan A')->assertDontSee('(2)');
    }

    public function test_marking_spam_teaches_the_sender_and_not_spam_forgets_it(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $this->pipe($this->raw('asha.user@mail.example.com', 'Cheap deals', 'Deals <deals@spammy.test>'));
        $m = MailMessage::where('subject', 'Cheap deals')->firstOrFail();
        $this->actingAs($u)->post("/mail/m/{$m->id}/do", ['action' => 'spam', 'from' => 'inbox'])->assertRedirect();
        $this->pipe($this->raw('asha.user@mail.example.com', 'More deals', 'Deals <deals@spammy.test>'));
        $this->assertSame('spam', MailMessage::where('subject', 'More deals')->value('folder'));
        $next = MailMessage::where('subject', 'More deals')->firstOrFail();
        $this->actingAs($u)->post("/mail/m/{$next->id}/do", ['action' => 'inbox', 'from' => 'spam'])->assertRedirect();
        $this->pipe($this->raw('asha.user@mail.example.com', 'Real offer', 'Deals <deals@spammy.test>'));
        $this->assertSame('inbox', MailMessage::where('subject', 'Real offer')->value('folder'));
    }

    public function test_contact_groups_are_saved_checked_and_offered_in_compose(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $this->actingAs($u)->post('/mail/settings/groups', ['name' => 'Team', 'emails' => "Ravi@Example.com, meena@example.com\nravi@example.com"])->assertSessionHasNoErrors();
        $this->actingAs($u)->post('/mail/settings/groups', ['name' => 'Bad', 'emails' => 'not-an-address'])->assertSessionHasErrors('emails');
        $this->assertSame('ravi@example.com, meena@example.com', \Modules\Mailbox\Models\MailContactGroup::where('name', 'Team')->value('emails'));
        $this->actingAs($u)->get('/mail/compose')->assertOk()->assertSee('Add a group to To...')->assertSee('ravi@example.com, meena@example.com');
        $other = $this->user('Mallory');
        $id = \Modules\Mailbox\Models\MailContactGroup::where('name', 'Team')->value('id');
        $this->actingAs($other)->delete("/mail/settings/groups/$id");
        $this->assertSame(1, \Modules\Mailbox\Models\MailContactGroup::count()); // not theirs
    }

    public function test_calendar_invites_show_their_event(): void
    {
        config(['app.timezone' => 'Asia/Kolkata']);
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha'])->assertRedirect('/mail');
        $ics = "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nSUMMARY:Quarterly review\\, Q3\r\nDTSTART:20991110T050000Z\r\nDTEND:20991110T060000Z\r\nLOCATION:Room 4\\, Fort office\r\nORGANIZER;CN=Ravi:mailto:ravi@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $b = 'b' . uniqid();
        $raw = "From: Ravi <ravi@example.com>\r\nTo: asha.user@mail.example.com\r\nSubject: Invite\r\nMessage-ID: <" . uniqid() . "@example.com>\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain\r\n\r\nSee you there\r\n--$b\r\nContent-Type: text/calendar; method=REQUEST; name=\"invite.ics\"\r\nContent-Disposition: attachment; filename=\"invite.ics\"\r\n\r\n$ics--$b--\r\n";
        $this->pipe($raw);
        $m = MailMessage::where('subject', 'Invite')->firstOrFail();
        $this->actingAs($u)->get("/mail/m/{$m->id}")->assertOk()->assertSee('Quarterly review, Q3')->assertSee('10 Nov 2099, 10:30')->assertSee('Room 4, Fort office')->assertSee('ravi@example.com')->assertDontSee('Yes, going'); // no UID: it cannot be answered
        $ics = str_replace('BEGIN:VEVENT\r\n', "BEGIN:VEVENT\r\nUID:evt-123@example.com\r\n", $ics);
        $this->pipe(str_replace(['Subject: Invite', 'Message-ID: <'], ['Subject: Invite 2', 'Message-ID: <x'], str_replace("BEGIN:VEVENT\r\nSUMMARY", "BEGIN:VEVENT\r\nUID:evt-123@example.com\r\nSUMMARY", $raw)));
        $m2 = MailMessage::where('subject', 'Invite 2')->firstOrFail();
        $this->actingAs($u)->get("/mail/m/{$m2->id}")->assertSee('Yes, going');
        $this->actingAs($u)->post("/mail/m/{$m2->id}/rsvp", ['answer' => 'accept'])->assertRedirect()->assertSessionHas('status');
        $sent = MailMessage::where('folder', 'sent')->where('subject', 'Accepted: Quarterly review, Q3')->firstOrFail();
        $this->assertSame(['ravi@example.com'], $sent->to);
        $reply = (string) \Illuminate\Support\Facades\Storage::disk('local')->get($sent->attachments()->firstOrFail()->path);
        $this->assertStringContainsString('METHOD:REPLY', $reply);
        $this->assertStringContainsString('UID:evt-123@example.com', $reply);
        $this->assertStringContainsString('PARTSTAT=ACCEPTED', $reply);
        $this->assertStringContainsString('mailto:asha.user@mail.example.com', $reply);
        $this->actingAs($u)->post("/mail/m/{$m->id}/rsvp", ['answer' => 'accept'])->assertStatus(422); // the first invite has no UID
        $this->actingAs($this->user('Mallory'))->post("/mail/m/{$m2->id}/rsvp", ['answer' => 'decline'])->assertNotFound();
    }

    public function test_a_shared_mailbox_is_read_and_used_only_while_shared(): void
    {
        $boss = $this->user('Boss');
        $pa = $this->user('Assistant');
        $other = $this->user('Mallory');
        $this->actingAs($boss)->post('/mail/settings/address', ['local' => 'boss'])->assertRedirect('/mail');
        $this->pipe($this->raw('boss.user@mail.example.com', 'Board meeting'));
        $m = MailMessage::where('subject', 'Board meeting')->firstOrFail();
        $acc = MailAccount::where('user_id', $boss->id)->firstOrFail();

        $this->actingAs($boss)->post('/mail/settings/delegates', ['email' => $boss->email])->assertSessionHasErrors('email');
        $this->actingAs($boss)->post('/mail/settings/delegates', ['email' => $pa->email])->assertSessionHasNoErrors();
        $this->actingAs($pa)->get('/mail')->assertSee('Shared with me')->assertSee('Boss')->assertDontSee('Board meeting');
        $this->actingAs($pa)->get('/mail?as=' . $boss->id)->assertSee('You are in the mailbox of Boss')->assertSee('Board meeting');
        $this->actingAs($pa)->get("/mail/m/{$m->id}")->assertOk()->assertSee('Board meeting');
        $this->actingAs($pa)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'ravi@example.com', 'subject' => 'On behalf', 'body' => 'x'])->assertRedirect();
        $this->assertSame(1, MailMessage::where('user_id', $boss->id)->where('folder', 'sent')->where('subject', 'On behalf')->count());
        $this->actingAs($pa)->get('/mail?as=0')->assertDontSee('Board meeting'); // back to their own

        // someone it was not shared with gets nothing
        $this->actingAs($other)->get('/mail?as=' . $boss->id)->assertDontSee('Board meeting');
        $this->actingAs($other)->get("/mail/m/{$m->id}")->assertNotFound();

        // taken back: the next request is refused even with the old choice in the session
        $this->actingAs($pa)->get('/mail?as=' . $boss->id)->assertSee('Board meeting');
        $this->actingAs($boss)->delete("/mail/settings/delegates/{$pa->id}")->assertRedirect();
        $this->actingAs($pa)->get("/mail/m/{$m->id}")->assertNotFound();
    }

    public function test_a_copy_of_new_mail_goes_to_the_outside_address_for_the_phone(): void
    {
        $u = $this->user();
        MailAccount::create(['user_id' => $u->id, 'kind' => 'local', 'email' => 'asha@mail.example.com', 'is_default' => true]);
        $this->actingAs($u)->post('/mail/settings', ['forward_to' => 'me@mail.example.com'])->assertSessionHasErrors('forward_to'); // would loop
        $this->actingAs($u)->post('/mail/settings', ['forward_to' => 'asha.personal@gmail.com'])->assertSessionHasNoErrors();

        $this->pipe($this->raw('asha@mail.example.com', 'Order 77'));
        $copy = MailMessage::where('folder', 'sent')->first();
        $this->assertNotNull($copy, 'copy sent and kept in Sent');
        $this->assertSame(['asha.personal@gmail.com'], $copy->to);
        $this->assertSame('Fwd: Order 77', $copy->subject);
        $this->assertStringContainsString('ravi@example.com', (string) $copy->body_html);

        // mail from the outside address itself is not sent back to it
        $this->pipe($this->raw('asha@mail.example.com', 'From my phone', 'Asha <asha.personal@gmail.com>'));
        $this->assertSame(1, MailMessage::where('folder', 'sent')->count());
    }

    public function test_quota_filters_and_vacation_reply(): void
    {
        $u = $this->user();
        $acc = MailAccount::create(['user_id' => $u->id, 'kind' => 'local', 'email' => 'asha@mail.example.com', 'is_default' => true]);
        $this->actingAs($u)->post('/mail/settings/filters', ['field' => 'subject', 'contains' => 'invoice', 'action' => 'label', 'value' => 'Bills']);
        $this->actingAs($u)->post('/mail/settings', ['vacation_on' => 1, 'vacation_subject' => 'Away', 'vacation_body' => 'Back on Monday']);

        $this->pipe($this->raw('asha@mail.example.com', 'Your invoice 42'));
        $m = MailMessage::where('folder', 'inbox')->firstOrFail();
        $this->assertSame(['Bills'], $m->labels->pluck('name')->all());
        $reply = MailMessage::where('folder', 'sent')->first();
        $this->assertNotNull($reply, 'vacation reply sent and kept in Sent');
        $this->assertSame(['ravi@example.com'], $reply->to);
        $this->pipe($this->raw('asha@mail.example.com', 'Second'));
        $this->assertSame(1, MailMessage::where('folder', 'sent')->count(), 'one auto-reply per sender');

        // full mailbox refuses new mail
        MailSetting::for($u->id)->forceFill(['quota_mb' => 1])->save();
        MailMessage::where('id', $m->id)->update(['size' => 2 * 1048576]);
        $this->pipe($this->raw('asha@mail.example.com', 'Too much'));
        $this->assertSame(0, MailMessage::where('subject', 'Too much')->count());
    }

    public function test_compose_send_reply_draft_and_safe_html(): void
    {
        $u = $this->user();
        $acc = MailAccount::create(['user_id' => $u->id, 'kind' => 'local', 'email' => 'asha@mail.example.com', 'is_default' => true]);
        $this->actingAs($u)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'not-an-email', 'subject' => 'x', 'body' => 'x'])->assertSessionHasErrors('to');
        $this->actingAs($u)->post('/mail/send', [
            'account_id' => $acc->id, 'to' => 'ravi@example.com, Neha <neha@example.com>', 'subject' => 'Plan',
            'body' => '<p>Hello <a href="javascript:alert(1)">x</a><img src=x onerror=alert(1)></p><script>bad()</script>',
            'files' => [UploadedFile::fake()->createWithContent('plan.txt', 'plan')],
        ])->assertRedirect('/mail?folder=sent');
        $sent = MailMessage::where('folder', 'sent')->firstOrFail();
        $this->assertSame(['ravi@example.com', 'neha@example.com'], $sent->to);
        $this->assertStringNotContainsString('javascript', (string) $sent->body_html);
        $this->assertStringNotContainsString('onerror', (string) $sent->body_html);
        $this->assertStringNotContainsString('bad()', (string) $sent->body_html);
        $this->assertTrue($sent->has_attachments);
        $this->assertDatabaseHas('mail_contacts', ['user_id' => $u->id, 'email' => 'neha@example.com']);
        $this->actingAs($u)->getJson('/mail/contacts?q=neh')->assertOk()->assertJsonPath('0.email', 'neha@example.com');

        $this->actingAs($u)->get("/mail/compose?reply={$sent->id}")->assertOk()->assertSee('Re: Plan');
        $this->actingAs($u)->post('/mail/send', ['account_id' => $acc->id, 'to' => 'ravi@example.com', 'subject' => 'Later', 'body' => '<p>draft</p>', 'save' => 'draft']);
        $draft = MailMessage::where('folder', 'drafts')->firstOrFail();
        $this->actingAs($u)->get("/mail/compose?draft={$draft->id}")->assertOk()->assertSee('Later');
        $this->actingAs($this->user('Mallory'))->post('/mail/send', ['account_id' => $acc->id, 'to' => 'x@example.com', 'subject' => 'x', 'body' => 'x'])->assertNotFound();
    }

    public function test_google_drive_archive_and_contacts(): void
    {
        $u = $this->user();
        $acc = MailAccount::create(['user_id' => $u->id, 'kind' => 'local', 'email' => 'asha@mail.example.com', 'is_default' => true]);
        $this->pipe($this->raw('asha@mail.example.com', 'Old news'));
        $m = MailMessage::firstOrFail();
        $m->forceFill(['sent_at' => now()->subDays(45)])->save();
        $s = MailSetting::for($u->id);
        $s->forceFill(['google_token' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => time() + 3000], 'google_email' => 'asha@gmail.com'])->save();
        Http::fake([
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'folder1']),
            'www.googleapis.com/upload/drive/v3/files*' => Http::response(['id' => 'file1']),
            'www.googleapis.com/drive/v3/files/file1*' => Http::response(DriveArchive::raw($m->fresh('attachments'))),
            'people.googleapis.com/*' => Http::response(['connections' => [['names' => [['displayName' => 'Neha']], 'emailAddresses' => [['value' => 'Neha@Example.com']]]]]),
        ]);
        (new \Modules\Mailbox\Jobs\SyncMailJob($u->id))->handle();
        $m->refresh();
        $this->assertSame('file1', $m->drive_file_id);
        $this->assertNull($m->body_html);
        $this->assertSame(0, \Modules\Mailbox\Services\Mailbox::used($u->id));
        $this->assertDatabaseHas('mail_contacts', ['user_id' => $u->id, 'email' => 'neha@example.com', 'source' => 'google']);
        $this->actingAs($u)->get("/mail/m/{$m->id}")->assertOk()->assertSee('Old news')->assertSee('there', false);
        $this->assertNull($s->fresh()->google_error);
    }

    public function test_search_words_and_star_from_the_list(): void
    {
        $u = $this->user();
        $this->actingAs($u)->post('/mail/settings/address', ['local' => 'asha.k'])->assertRedirect('/mail');
        $this->pipe($this->raw('asha.k.user@mail.example.com', 'Quarterly report'));
        $this->pipe($this->raw('asha.k.user@mail.example.com', 'Lunch plan', 'Meera <meera@example.org>'));
        $this->actingAs($u)->get('/mail?q=' . urlencode('from:meera'))->assertOk()->assertSee('Lunch plan')->assertDontSee('Quarterly report');
        $this->actingAs($u)->get('/mail?q=' . urlencode('subject:quarterly has:attachment'))->assertOk()->assertSee('Quarterly report')->assertDontSee('Lunch plan');
        $lunch = MailMessage::where('subject', 'Lunch plan')->firstOrFail();
        $lunch->update(['folder' => 'archive']);
        $this->actingAs($u)->get('/mail?q=' . urlencode('lunch'))->assertOk()->assertDontSee('Lunch plan');
        $this->actingAs($u)->get('/mail?q=' . urlencode('lunch in:anywhere'))->assertOk()->assertSee('Lunch plan');
        $this->actingAs($u)->from('/mail')->post('/mail/m/' . $lunch->id . '/do', ['action' => 'star'])->assertRedirect('/mail');
        $this->assertTrue((bool) $lunch->fresh()->starred);
    }
}
