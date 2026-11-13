<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Archive;
use Modules\Admin\Services\ArchiveService;

class CreateArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly Archive $archive,
    ) {
    }

    public function handle(ArchiveService $archives): void
    {
        $archives->build($this->archive);
    }
}
