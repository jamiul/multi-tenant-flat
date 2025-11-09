<?php

namespace App\Jobs;

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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        try {
            if (Storage::disk('public')->exists($this->filePath)) {
                $exportData = Cache::get("export_{$this->exportId}");
                
                if ($exportData) {
                    $exportData['status'] = 'completed';
                    $exportData['progress'] = 100;
                    $exportData['completed_at'] = now()->toDateTimeString();
                    $exportData['file_url'] = Storage::disk('public')->url($this->filePath);
                    
                    Cache::put("export_{$this->exportId}", $exportData, now()->addHours(24));
                }

                Log::info("Export completed and file available", [
                    'export_id' => $this->exportId,
                    'path' => $this->filePath
                ]);
            } else {
                Log::error("File not found after export", [
                    'export_id' => $this->exportId,
                    'path' => $this->filePath
                ]);
                
                Cache::put("export_{$this->exportId}", [
                    'status' => 'failed',
                    'error_message' => 'Export file not found after processing',
                ], now()->addHours(24));
            }
        } catch (\Throwable $e) {
            Log::error("Failed to mark export as completed", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}