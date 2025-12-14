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
use Rap2hpoutre\FastExcel\FastExcel;

class MergeExportFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tempDir;
    public $finalFilePath;
    public $userId;
    public $exportId;

    public $timeout = 3600; // 60 minutes
    public $tries = 3;
    public $maxExceptions = 3;
    public $backoff = 30;

    // Memory limit for the job
    public $memory = '2048M'; // 2GB

    public function __construct(string $tempDir, string $finalFilePath, int $userId, string $exportId)
    {
        $this->tempDir = $tempDir;
        $this->finalFilePath = $finalFilePath;
        $this->userId = $userId;
        $this->exportId = $exportId;
    }

    public function handle()
    {
        $disk = Storage::disk('public');

        Log::info("=== MERGE JOB STARTED (FastExcel) ===", [
            'export_id' => $this->exportId,
            'temp_dir' => $this->tempDir,
            'final_path' => $this->finalFilePath,
            'user_id' => $this->userId,
            'attempt' => $this->attempts(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]);

        // Increase PHP memory and execution limits
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '3600');

        if (!$disk->exists($this->tempDir)) {
            throw new \Exception("Temp directory not found: {$this->tempDir}");
        }

        // 1️⃣ Collect & sort chunk CSVs
        $chunkFiles = array_values(array_filter($disk->files($this->tempDir), fn($f) => str_ends_with($f, '.xlsx')));

        usort($chunkFiles, function ($a, $b) {
            preg_match('/chunk_(\d+)\.xlsx$/', $a, $ma);
            preg_match('/chunk_(\d+)\.xlsx$/', $b, $mb);
            return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
        });

        if (empty($chunkFiles)) {
            throw new \Exception("No CSV chunk files found in {$this->tempDir}");
        }

        Log::info("Found chunk files", [
            'export_id' => $this->exportId,
            'chunk_count' => count($chunkFiles)
        ]);

        // 2️⃣ Merge CSV chunks and convert to XLSX using FastExcel
        try {
            $this->updateProgress(10, "Starting merge process...");
            
            $totalRows = $this->mergeChunksToXlsx($disk, $chunkFiles);

            if ($totalRows === 0) {
                throw new \Exception("No data was processed from chunks");
            }

            Log::info("Merged XLSX created successfully with FastExcel", [
                'export_id' => $this->exportId,
                'final_path' => $this->finalFilePath,
                'total_rows' => $totalRows
            ]);
        } catch (\Throwable $e) {
            Log::error("XLSX conversion failed", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // 3️⃣ Cleanup chunk files & mark as completed
        $this->cleanupTempFiles($disk, $chunkFiles);
        $this->markExportCompleted($totalRows);

        Log::info("=== MERGE JOB COMPLETED SUCCESSFULLY ===", [
            'export_id' => $this->exportId,
            'total_rows' => $totalRows,
            'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB'
        ]);
    }

    private function mergeChunksToXlsx($disk, array $chunkFiles): int
    {
        Log::info("Starting chunk merge with FastExcel", [
            'export_id' => $this->exportId,
            'total_chunks' => count($chunkFiles)
        ]);

        $this->updateProgress(20, "Reading chunk files...");

        $totalRows = 0;
        $allData = [];

        // Read all chunks and combine data
        foreach ($chunkFiles as $index => $chunkKey) {
            try {
                $chunkPath = $disk->path($chunkKey);
                
                if (!file_exists($chunkPath)) {
                    Log::warning("Chunk file not found", ['chunk' => $chunkKey]);
                    continue;
                }

                // Read CSV chunk using FastExcel
                $chunkData = (new FastExcel)->import($chunkPath);
                
                $chunkRows = $chunkData->count();
                $totalRows += $chunkRows;

                // Add to combined data array
                foreach ($chunkData as $row) {
                    $allData[] = $row;
                }

                // Update progress every few chunks
                if (($index + 1) % 5 === 0) {
                    $progress = 20 + (int)((($index + 1) / count($chunkFiles)) * 60); // 20-80% for reading
                    $this->updateProgress($progress, "Processing chunks... (" . ($index + 1) . "/" . count($chunkFiles) . ")");
                }

                Log::info("Chunk read", [
                    'export_id' => $this->exportId,
                    'chunk' => $index + 1,
                    'total' => count($chunkFiles),
                    'rows' => $chunkRows
                ]);

            } catch (\Throwable $e) {
                Log::error("Error reading chunk", [
                    'export_id' => $this->exportId,
                    'chunk' => $chunkKey,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        $this->updateProgress(85, "Creating final Excel file...");

        // Export all data to XLSX using FastExcel
        $finalPath = $disk->path($this->finalFilePath);
        
        // Ensure directory exists
        $directory = dirname($finalPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        Log::info("Exporting to XLSX", [
            'export_id' => $this->exportId,
            'total_rows' => $totalRows,
            'final_path' => $finalPath
        ]);

        // Use FastExcel to export to XLSX
        // FastExcel automatically detects the format from file extension
        (new FastExcel(collect($allData)))->export($finalPath);

        // Verify file was created
        if (!file_exists($finalPath)) {
            throw new \Exception("Failed to create final XLSX file");
        }

        Log::info("XLSX file created successfully", [
            'export_id' => $this->exportId,
            'file_size' => round(filesize($finalPath) / 1024 / 1024, 2) . 'MB'
        ]);

        $this->updateProgress(95, "Finalizing...");

        // Force garbage collection
        unset($allData, $chunkData);
        gc_collect_cycles();

        return $totalRows;
    }

    private function updateProgress(int $progress, string $message = ''): void
    {
        try {
            $exportData = Cache::get("export_{$this->exportId}");
            if ($exportData) {
                $exportData['progress'] = $progress;
                $exportData['status'] = 'processing';
                if ($message) {
                    $exportData['message'] = $message;
                }
                Cache::put("export_{$this->exportId}", $exportData, now()->addHours(24));
                
                Log::info("Progress updated", [
                    'export_id' => $this->exportId,
                    'progress' => $progress,
                    'message' => $message
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to update progress", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function cleanupTempFiles($disk, array $chunkFiles): void
    {
        try {
            Log::info("Starting cleanup", [
                'export_id' => $this->exportId,
                'temp_dir' => $this->tempDir,
                'chunk_count' => count($chunkFiles)
            ]);

            $deletedCount = 0;
            $failedDeletes = [];

            foreach ($chunkFiles as $chunkFile) {
                try {
                    if ($disk->exists($chunkFile)) {
                        $deleted = $disk->delete($chunkFile);
                        if ($deleted) {
                            $deletedCount++;
                        } else {
                            $failedDeletes[] = $chunkFile;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to delete chunk file", [
                        'export_id' => $this->exportId,
                        'file' => $chunkFile,
                        'error' => $e->getMessage()
                    ]);
                    $failedDeletes[] = $chunkFile;
                }
            }

            // Delete temp directory
            try {
                if ($disk->exists($this->tempDir)) {
                    $deleted = $disk->deleteDirectory($this->tempDir);
                    if ($deleted) {
                        Log::info("Temp directory deleted", [
                            'export_id' => $this->exportId,
                            'temp_dir' => $this->tempDir
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Error deleting temp directory", [
                    'export_id' => $this->exportId,
                    'temp_dir' => $this->tempDir,
                    'error' => $e->getMessage()
                ]);
            }

            Log::info("Cleanup completed", [
                'export_id' => $this->exportId,
                'chunks_deleted' => $deletedCount,
                'failed_deletes' => count($failedDeletes)
            ]);
        } catch (\Throwable $e) {
            Log::error("Cleanup process failed", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function markExportCompleted(int $totalRows): void
    {
        try {
            $exportData = Cache::get("export_{$this->exportId}");

            Log::info("Marking export as completed", [
                'export_id' => $this->exportId,
                'cache_exists' => !is_null($exportData),
                'total_rows' => $totalRows
            ]);

            if ($exportData) {
                $exportData['status'] = 'completed';
                $exportData['progress'] = 100;
                $exportData['completed_at'] = now()->toDateTimeString();
                $exportData['total_rows'] = $totalRows;
                $exportData['file_url'] = Storage::url($this->finalFilePath);
                $exportData['message'] = 'Export completed successfully';

                Cache::put("export_{$this->exportId}", $exportData, now()->addHours(24));

                // Verify cache was updated
                sleep(1);
                $verifyCache = Cache::get("export_{$this->exportId}");

                if ($verifyCache && $verifyCache['status'] === 'completed') {
                    Log::info("=== CACHE VERIFICATION SUCCESS ===", [
                        'export_id' => $this->exportId,
                        'status' => $verifyCache['status'],
                        'file_url' => $verifyCache['file_url']
                    ]);
                } else {
                    Log::error("=== CACHE VERIFICATION FAILED ===", [
                        'export_id' => $this->exportId,
                        'expected_status' => 'completed',
                        'actual_status' => $verifyCache['status'] ?? 'null'
                    ]);
                }
            } else {
                Log::error("Cannot mark as completed - cache data not found", [
                    'export_id' => $this->exportId
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to mark export as completed", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("=== MERGE JOB FAILED PERMANENTLY ===", [
            'export_id' => $this->exportId,
            'temp_dir' => $this->tempDir,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB'
        ]);

        try {
            Cache::put("export_{$this->exportId}", [
                'status' => 'failed',
                'error_message' => "Export merge failed: " . $exception->getMessage(),
                'failed_at' => now()->toDateTimeString(),
                'progress' => 0
            ], now()->addHours(24));
        } catch (\Throwable $e) {
            Log::error("Failed to update cache with failure status", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage()
            ]);
        }
    }
}