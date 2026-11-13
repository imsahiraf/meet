#!/usr/bin/env bash
# The owner's PERSONAL public copy of Meet (owner 2026-11-13): github.com/imsahiraf/meet - the Community copy with no Meet / Meet /
# imsahiraf name anywhere, and its mail hub only syncs the person's other mail accounts (send and receive through them); it never hands
# out addresses of its own (MEET_MAIL_DOMAIN is always empty here).
#   scripts/make-personal.sh [destination]      default: ~/example/projects/meet-personal (own git repository, remote imsahiraf/meet)
# Stops when any "meet" is left or a PHP file does not parse.
set -euo pipefail
cd "$(dirname "$0")/.."
DEST="${1:-$HOME/example/projects/meet-personal}"
VERSION="$(cat VERSION 2>/dev/null || echo dev)"
STAGE="$(mktemp -d)"; trap 'rm -rf "$STAGE"' EXIT
scripts/make-community.sh "$STAGE/src-copy" >/dev/null
rm -rf "$STAGE/src-copy/.git"
S="$STAGE/src-copy"
python3 - "$S" <<'PY'
import os, re, sys
root = sys.argv[1]
swaps = [
    (r'https?://(www\.)?meet\.in/contact/?', 'https://github.com/imsahiraf/meet/issues'),
    (r'https?://meet\.meet\.in[^\s"\')<]*', 'https://github.com/imsahiraf/meet'),
    (r'https?://[a-z.]*meet(x|co|matrix)\.(in|com)[^\s"\')<]*', 'https://github.com/imsahiraf/meet'),
    (r'mail\.meet\.in', 'mail.example.com'), (r'mail\.example\.com', 'mail.example.org'), (r'notexample', 'notexample'),
    (r'meet\.in', 'example.com'), (r'meet\.com', 'example.net'), (r'example\.com', 'example.org'), (r'example\.in', 'example.in'),
    (r'meetmatrix\.com', 'example.biz'), (r'meet-mail-pipe', 'meet-mail-pipe'), (r'Demo@meet1', 'Demo@meet1'),
    (r'Meet', 'Meet'), (r'Meet', 'Meet'), (r'Meet Company \(imsahiraf\)', 'imsahiraf'), (r'imsahiraf', 'imsahiraf'),
    (r'imsahiraf', 'imsahiraf'), (r'example', 'example'), (r'meetuser', 'meetuser'), (r'Meet', 'Meet'), (r'meet', 'meet'),
    (r'Meet', 'Meet'), (r'Meet', 'Meet'), (r'meet', 'meet'),
]
for dp, dn, fn in os.walk(root):
    if '/node_modules' in dp or '/vendor' in dp:
        continue
    for f in fn:
        p = os.path.join(dp, f)
        try:
            s = open(p, encoding='utf-8').read()
        except (UnicodeDecodeError, IsADirectoryError):
            continue
        n = s
        for a, b in swaps:
            n = re.sub(a, b, n)
        if n != s:
            open(p, 'w', encoding='utf-8').write(n)
for dp, dn, fn in os.walk(root, topdown=False):
    for name in fn + dn:
        if 'meet' in name.lower():
            new = re.sub('meet-mail-pipe', 'meet-mail-pipe', name)
            new = re.sub('Meet', 'Meet', new); new = re.sub('(?i)meet[a-z]*', 'meet', new)
            os.rename(os.path.join(dp, name), os.path.join(dp, new))
PY
# the mail hub never gives out its own addresses in this copy: only the person's other accounts (Gmail, Outlook, IMAP) are synced
sed -i "s/'domain' => env('MEET_MAIL_DOMAIN', ''),/'domain' => '', \/\/ personal copy: no own addresses, other accounts only/" "$S/Modules/Mailbox/config/config.php"
sed -i "s/'alias_domains' => env('MEET_MAIL_ALIAS_DOMAINS', '[^']*'),/'alias_domains' => '',/" "$S/Modules/Mailbox/config/config.php"
cat > "$S/LICENSE" <<'TERMS'
Meet - free to use, no licence key, no fee.

Copyright (c) imsahiraf. All rights reserved.
- You may install and use it on your own servers and change it for your own use.
- You may not sell it, rent it out or offer it as your own product.
- It is given as is, without any warranty and without a support promise.
TERMS
cat > "$S/README.md" <<EOF
# Meet

Chat, voice and video calls and meetings (captions, recording, breakout rooms, notes) for a small team, self-hosted, plus a mail hub
that brings your existing mail accounts (Gmail, Outlook or any IMAP / SMTP account) into the same app - read, reply and send through
them. A side project I build in my free time. Version $VERSION.

## Run it

Needs PHP 8.4 with the usual Laravel extensions, Composer, MySQL / MariaDB (or SQLite to try it) and Node 20 for the assets.

    composer install --no-dev
    cp .env.example .env && php artisan key:generate
    php artisan migrate --seed
    npm ci && npm run build

Then open public/install.php once to create the first administrator, or run \`php artisan serve\` to try it.
Push notifications need your own Firebase project (FIREBASE_* in .env).

Free to use - see LICENSE for the short terms. Ideas and pull requests are welcome.
EOF
left="$(grep -rIil 'meet' "$S" --exclude-dir=node_modules --exclude-dir=vendor || true)"
[ -z "$left" ] || { echo "A Meet name is left in:"; echo "$left" | head; exit 1; }
bad=0; while IFS= read -r f; do php8.4 -l "$f" >/dev/null 2>&1 || { echo "PHP error: $f"; bad=1; }; done < <(find "$S" -name '*.php' -not -path '*/node_modules/*' -not -path '*/vendor/*')
[ "$bad" -eq 0 ] || exit 1
mkdir -p "$DEST"
if [ -d "$DEST/.git" ]; then find "$DEST" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +; fi
cp -a "$S/." "$DEST/"
cd "$DEST"
[ -d .git ] || git init -q -b main
git add -A
git -c user.name=imsahiraf -c user.email=imsahiraf@users.noreply.github.com commit -q -m "Meet $VERSION" 2>/dev/null && echo "Committed in $DEST" || echo "No changes."
echo "Files: $(git ls-files | wc -l)"
