# Meet Mail - server setup (cPanel)

For the person who runs the server. Everything below is done once. Users then create their address in Meet > Mail.

## 1. Choose the mail domain

Personal Meet addresses look like `name.user@example.com` (owner decision 2026-10-20: everyone gets `firstname.lastname@example.com`
from Meet; Meet Insights hosts only customers' own company domains). Use **`example.com`** itself. Mailboxes that already exist
in cPanel (for example `you@example.com`) keep working: cPanel delivers to its own mailboxes and forwarders first and hands
only the other addresses to the Default Address below. The `.user` part keeps personal addresses apart from the company's own cPanel mailboxes.

Meet Insights shows the same mailbox in its Mail Hub through Meet's mail API (`/api/insights/mail/*`, signed with the shared
`INSIGHTS_SSO_SECRET` that Insights > Control center > Integrations > Meet also holds): Insights users create the address there,
new mail is pulled every hour and mail written in Insights is sent by Meet.
(A subdomain such as `mail.example.com` also works if you prefer to keep the two apart.)

In Meet's `.env` (since 1.3.2 the update fills `MEET_MAIL_DOMAIN`, `MEET_MAIL_SUFFIX`, `INSIGHTS_SSO_SECRET` and `INSIGHTS_URL` by itself from `deploy/env-defaults.php` when they are empty - change them here only to use other values):

```
MEET_MAIL_DOMAIN=example.com
MEET_MAIL_SUFFIX=.user        # every personal address is <name>.user@example.com (owner decision 2026-10-20)
MEET_MAIL_ALIAS_DOMAINS=example.net   # name.user@example.net reaches the same mailbox (domain must be on the same cPanel account)
MEET_MAIL_FREE_MB=30          # free mailbox size per user
MEET_MAIL_ARCHIVE_DAYS=30     # mail older than this moves to the user's Google Drive (when Google is connected)
MEET_MAIL_MAX_MESSAGE_MB=20   # largest single message accepted / sent
```

## 2. DNS for the mail domain (cPanel > Zone Editor)

| Record | Name | Value |
|---|---|---|
| MX | `example.com` | `mail.example.com` (priority 0) - or the MX host cPanel shows for the account |
| A | `mail.example.com` | the server IP (only if it is not there yet) |
| TXT (SPF) | `example.com` | `v=spf1 +a +mx +include:<your host's SPF> ~all` (cPanel > Email Deliverability shows the exact value) |
| TXT (DKIM) | `default._domainkey.example.com` | from cPanel > Email Deliverability > Manage > DKIM |
| TXT (DMARC) | `_dmarc.example.com` | `v=DMARC1; p=quarantine; rua=mailto:postmaster@example.com` |

Check everything in cPanel > Email Deliverability: all items must be green, otherwise Gmail / Outlook put the mail in spam.

## 3. Send every message to Meet (cPanel > Email > Default Address)

Since 1.3.4 Meet does this by itself (`php artisan mail:cpanel-setup`, on every update and daily): it writes
`~/meet-mail-pipe.sh` and sets the domain's Default Address to pipe into it through the cPanel API. It needs either a
hosting where PHP may run the `uapi` command, or `CPANEL_API_TOKEN` (+ `CPANEL_USER`) in `.env` - in Meet Insights:
Control center > Environment > Meet. `storage/app/cpanel-mail.json` shows the last result, and admins see it in Meet > Mail > Settings > **Mail server (admins)**
with a "Set it now" button and the time mail last came in (since 1.4.1). The manual steps below are
only needed when neither is available.


1. Domain: `example.com`.
2. Advanced Options > **Pipe to a Program**:
   `/usr/local/bin/php -q /home/<cpanel-user>/meet/artisan mailbox:pipe`
   (use the real folder of Meet; `which php` in cPanel Terminal shows the PHP path, use PHP 8.4).
3. Save. Every mail to any address of the domain now reaches Meet. Addresses nobody has created are ignored (written to
   `storage/logs/laravel.log`), so nothing bounces back to senders.

Test: send a mail from Gmail to an address you created in Meet; it appears in Meet > Mail > Inbox within seconds.

## 4. Outgoing mail

Meet addresses send through the SMTP account set in Meet > Admin > SMTP settings (or `MAIL_*` in `.env`). Create one
mailbox in cPanel (for example `noreply@mail.example.com`) and use its SMTP login there; the From address of each message is
the user's own address, which SPF / DKIM above allow.

Gmail, Yahoo, Rediffmail, Zoho and Outlook accounts that users add send through their own provider (nothing to set up).

## 5. Google (free sync for every user)

Uses the same Google Cloud OAuth client as "Sign in with Google" (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`).

1. Google Cloud console > APIs & Services > Library: enable **Gmail API**, **Google Drive API**, **People API**.
2. Credentials > the OAuth client > Authorized redirect URIs: add `https://github.com/imsahiraf/meet
   (or set `MEET_MAIL_GOOGLE_REDIRECT` to the exact URL you add).
3. OAuth consent screen: add the scopes `https://mail.google.com/`, `.../auth/drive.file`, `.../auth/contacts.readonly`.
   Until Google verifies the app, add testers' e-mails under "Test users" (Google shows a warning screen to others).

## 6. Cron

The normal Meet scheduler line already runs the hourly mail sync (external accounts, Drive move, Google contacts):

```
* * * * * cd /home/<cpanel-user>/meet && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

Updates of Meet itself: `deploy/update.php` (nightly) or scheduled from imsahiraf Insights > Control center > System.

## 7. Insights packages

Users who open Meet from imsahiraf Insights get the mailbox size of their organization's package (Starter 1 GB, Suite 5 GB,
industry packages 2 GB - `mail_mb` in Insights' package limits). Free users keep the free size and can connect Google.
Set the same `INSIGHTS_SSO_SECRET` in Meet and in Insights > Control center > Integrations > Meet.
