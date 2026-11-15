# Meet

Chat, voice and video calls and meetings (captions, recording, breakout rooms, notes) for a small team, self-hosted, plus a mail hub
that brings your existing mail accounts (Gmail, Outlook or any IMAP / SMTP account) into the same app - read, reply and send through
them. A side project I build in my free time. Version 1.9.0.

## Run it

Needs PHP 8.4 with the usual Laravel extensions, Composer, MySQL / MariaDB (or SQLite to try it) and Node 20 for the assets.

    composer install --no-dev
    cp .env.example .env && php artisan key:generate
    php artisan migrate --seed
    npm ci && npm run build

Then open public/install.php once to create the first administrator, or run `php artisan serve` to try it.
Push notifications need your own Firebase project (FIREBASE_* in .env).

Free to use - see LICENSE for the short terms. Ideas and pull requests are welcome.
