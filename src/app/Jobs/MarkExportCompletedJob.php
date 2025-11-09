<?php

namespace App\Jobs;

use App\Exports\CustomersExport;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class MarkExportCompletedJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $exportId;
    public $filePath;
    public $userId;

    public function __construct(string $exportId, string $filePath, int $userId)
    {
        $this->exportId = $exportId;
        $this->filePath = $filePath;
        $this->userId = $userId;
    }

    public function handle()
    {
        if (Storage::disk('public')->exists($this->filePath)) {
            Cache::put("export_{$this->exportId}", [
                'user_id' => $this->userId,
                'file_path' => $this->filePath,
                'file_url' => asset('storage/' . $this->filePath),
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now()->toDateTimeString(),
            ], now()->addHours(24));

            Log::info("Export completed and file available", [
                'export_id' => $this->exportId,
                'path' => $this->filePath
            ]);
        } else {
            Log::error("File not found after export", [
                'export_id' => $this->exportId,
                'path' => $this->filePath
            ]);
        }
    }
}
