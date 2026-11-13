<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Mail\ArchiveDeliveryMail;
use Modules\Admin\Models\Archive;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Authentication\Models\User;
use ZipArchive;

/**
 * SRS-21 Archive/Backup-Mail Engine (FR-346-FR-360). Runs as a
 * background job (see Jobs\CreateArchiveJob) - this service holds the
 * actual archive-building logic so it's independently unit-testable
 * without dispatching a real queued job.
 *
 * Database export content is a curated table list (Chats, Meetings,
 * Whiteboards, Activity/Audit Logs per FR-346) rather than a full
 * `mysqldump`, since shared cPanel hosting frequently disables
 * `exec()`/`shell_exec()` - a pure-PHP JSON export works everywhere
 * `php artisan` itself runs.
 */
class ArchiveService
{
    private const DATABASE_TABLES = [
        'conversations', 'messages', 'meetings', 'meeting_participants',
        'whiteboards', 'whiteboard_objects', 'activity_logs', 'audit_logs',
    ];

    // Above this size, email a signed download link instead of an
    // attachment (FR-350).
    private const MAX_EMAIL_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    public function create(?User $actor, string $scope, string $trigger): Archive
    {
        return Archive::create([
            'scope' => $scope,
            'trigger' => $trigger,
            'requested_by' => $actor?->id,
            'status' => 'queued',
        ]);
    }

    public function build(Archive $archive): void
    {
        $archive->forceFill(['status' => 'processing'])->save();

        try {
            $workDir = storage_path('app/archive-tmp/'.$archive->uuid);
            File::ensureDirectoryExists($workDir);

            if (in_array($archive->scope, ['entire_system', 'database_only'], true)) {
                $this->exportDatabase($workDir);
            }

            $zipPath = storage_path('app/private/archives/'.$archive->uuid.'.zip');
            File::ensureDirectoryExists(dirname($zipPath));

            $settings = ArchiveSetting::current();
            $this->buildZip($workDir, $zipPath, $archive->scope, $settings);

            File::deleteDirectory($workDir);

            $archive->forceFill([
                'status' => 'completed',
                'disk_path' => 'archives/'.$archive->uuid.'.zip',
                'size_bytes' => filesize($zipPath),
                'checksum' => hash_file('sha256', $zipPath),
            ])->save();

            $this->deliver($archive, $settings);
        } catch (\Throwable $e) {
            $archive->forceFill(['status' => 'failed', 'failure_reason' => $e->getMessage()])->save();

            throw $e;
        }
    }

    private function exportDatabase(string $workDir): void
    {
        foreach (self::DATABASE_TABLES as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->limit(10000)->get();
            File::put($workDir.'/'.$table.'.json', $rows->toJson(JSON_PRETTY_PRINT));
        }
    }

    private function buildZip(string $workDir, string $zipPath, string $scope, ArchiveSetting $settings): void
    {
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if (is_dir($workDir)) {
            foreach (File::files($workDir) as $file) {
                $zip->addFile($file->getPathname(), 'database/'.$file->getFilename());
            }
        }

        if (in_array($scope, ['entire_system', 'files_only'], true) && Storage::disk('local')->exists('media')) {
            $this->addDirectoryToZip($zip, Storage::disk('local')->path('media'), 'files');
        }

        if ($settings->password_protection_enabled && $zip->numFiles > 0) {
            $zip->setPassword($settings->archive_password);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
            }
        }

        $zip->close();
    }

    private function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void
    {
        if (! is_dir($sourceDir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $localName = $zipPrefix.'/'.substr($file->getPathname(), strlen($sourceDir) + 1);
                $zip->addFile($file->getPathname(), $localName);
            }
        }
    }

    private function deliver(Archive $archive, ArchiveSetting $settings): void
    {
        $absolutePath = storage_path('app/private/'.$archive->disk_path);
        $attach = $archive->size_bytes <= self::MAX_EMAIL_ATTACHMENT_BYTES;

        Mail::to($settings->recipient_email)->send(new ArchiveDeliveryMail($archive, $attach ? $absolutePath : null));

        $archive->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
    }
}
