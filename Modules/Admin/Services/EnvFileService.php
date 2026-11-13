<?php

namespace Modules\Admin\Services;

/**
 * A field-by-field admin UI and a raw-file editor both need to read/
 * write the real .env - Laravel itself has no built-in API for this
 * (env() only ever reads, never writes). Mirrors deploy/lib/DeployEnv.php's
 * exact quoting/escaping convention (always double-quotes+escapes a
 * written value - dotenv treats a bare '#' as a comment marker, which
 * once silently truncated a real SMTP password mid-value) since that
 * script and this service can both end up touching the same file -
 * not reused directly because deploy/ runs as a standalone pre-
 * bootstrap PHP script, outside this app's own autoloading.
 */
class EnvFileService
{
    /**
     * Overridable (not hardcoded to base_path()) specifically so tests
     * can bind this service to temp fixture paths instead of ever
     * touching the real project .env - see
     * tests/Feature/Admin/EnvironmentManagementTest.php.
     */
    public function __construct(
        private readonly ?string $envPath = null,
        private readonly ?string $envExamplePath = null,
    ) {
    }

    public function path(): string
    {
        return $this->envPath ?? base_path('.env');
    }

    public function examplePath(): string
    {
        return $this->envExamplePath ?? base_path('.env.example');
    }

    public function rawContents(): string
    {
        return is_file($this->path()) ? file_get_contents($this->path()) : '';
    }

    /**
     * Ordered key => value, in the order they appear in the file -
     * comments and blank lines are skipped (the field editor only
     * ever shows/edits real KEY=value entries; the raw editor is the
     * escape hatch for anything else, like comments).
     */
    public function all(): array
    {
        return $this->parseFile($this->path());
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Replaces a key's existing line if present, appends it otherwise
     * - every other line (including comments/blank lines/ordering) is
     * left untouched, unlike writeRaw() which replaces everything.
     */
    public function set(string $key, string $value): void
    {
        $lines = is_file($this->path()) ? file($this->path(), FILE_IGNORE_NEW_LINES) : [];
        $needle = $key.'=';
        $encoded = $key.'='.$this->quote($value);
        $found = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, $needle)) {
                $lines[$i] = $encoded;
                $found = true;
                break;
            }
        }

        if (! $found) {
            $lines[] = $encoded;
        }

        $this->backup();
        file_put_contents($this->path(), implode(PHP_EOL, $lines).PHP_EOL);
    }

    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->set($key, (string) $value);
        }
    }

    /**
     * Wholesale replace, for the raw-editor mode - the admin is
     * trusted to have typed a valid .env, unlike set()'s surgical
     * single-line replace.
     */
    public function writeRaw(string $content): void
    {
        $this->backup();
        file_put_contents($this->path(), $content);
    }

    /**
     * A dated copy before every write (set()/setMany()/writeRaw()) -
     * DeployEnv itself has no such safety net; a UI-driven editor
     * genuinely needs one, since a malformed raw save here has no
     * other rollback path.
     */
    private function backup(): void
    {
        if (! is_file($this->path())) {
            return;
        }

        $dir = storage_path('app/env-backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        copy($this->path(), $dir.'/env-'.now()->format('Ymd-His').'.bak');
    }

    /**
     * Keys present in .env.example but missing or blank in the real
     * .env - the same "a new version added keys" scenario
     * deploy/update.php's syncNewEnvKeys() already detects and emails
     * about; this exposes that same information in the admin UI so
     * it's visible without digging through email/deploy logs.
     */
    public function missingOrBlankKeys(): array
    {
        $current = $this->all();
        $example = is_file($this->examplePath()) ? $this->parseFile($this->examplePath()) : [];

        $missing = [];
        foreach (array_keys($example) as $key) {
            if (! array_key_exists($key, $current) || trim($current[$key]) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function parseFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $result = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $result[$key] = $this->unquote($value);
        }

        return $result;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** Reverses quote() - also tolerates a plain unquoted value, since a human can edit .env by hand. */
    private function unquote(string $value): string
    {
        if (strlen($value) < 2 || $value[0] !== '"' || ! str_ends_with($value, '"')) {
            return $value;
        }

        $inner = substr($value, 1, -1);
        $result = '';

        for ($i = 0, $len = strlen($inner); $i < $len; $i++) {
            if ($inner[$i] === '\\' && $i + 1 < $len && ($inner[$i + 1] === '"' || $inner[$i + 1] === '\\')) {
                $result .= $inner[$i + 1];
                $i++;
            } else {
                $result .= $inner[$i];
            }
        }

        return $result;
    }
}
