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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

class MergeExportFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tempDir;
    public $finalFilePath;
    public $userId;
    public $exportId;

    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $maxExceptions = 3;
    public $backoff = 30;

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

        Log::info("=== MERGE JOB STARTED ===", [
            'export_id' => $this->exportId,
            'temp_dir' => $this->tempDir,
            'final_path' => $this->finalFilePath,
            'user_id' => $this->userId,
            'attempt' => $this->attempts(),
            'full_temp_path' => (method_exists($disk, 'path') ? $disk->path($this->tempDir) : $this->tempDir)
        ]);

        // Ensure temp dir exists (for local driver this will be a real path check)
        if (!$disk->exists($this->tempDir)) {
            Log::error("Temp directory not found", [
                'export_id' => $this->exportId,
                'temp_dir' => $this->tempDir,
                'full_path' => (method_exists($disk, 'path') ? $disk->path($this->tempDir) : $this->tempDir)
            ]);
            throw new \Exception("Temp directory not found: {$this->tempDir}");
        }

        // Get chunk file list
        $chunkFiles = $disk->files($this->tempDir);

        Log::info("Scanning for chunk files", [
            'export_id' => $this->exportId,
            'temp_dir' => $this->tempDir,
            'found_files' => count($chunkFiles),
            'files' => $chunkFiles
        ]);

        // Filter to CSVs and sort
        $chunkFiles = array_values(array_filter($chunkFiles, function ($f) {
            return str_ends_with($f, '.csv');
        }));

        usort($chunkFiles, function ($a, $b) {
            preg_match('/chunk_(\d+)\.csv$/', $a, $ma);
            preg_match('/chunk_(\d+)\.csv$/', $b, $mb);
            return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
        });

        if (empty($chunkFiles)) {
            Log::error("No .csv chunk files found", [
                'export_id' => $this->exportId,
                'temp_dir' => $this->tempDir
            ]);
            throw new \Exception("No chunk CSV files found in {$this->tempDir}");
        }

        // Create a local temporary final file (merge here, then push to storage)
        $tmpFinal = sys_get_temp_dir() . '/merge_export_' . $this->exportId . '_' . uniqid() . '.csv';
        $outHandle = fopen($tmpFinal, 'w');

        if ($outHandle === false) {
            throw new \Exception("Unable to create temp output file: {$tmpFinal}");
        }

        $isFirst = true;
        $processedChunks = 0;
        $totalRows = 0;

        foreach ($chunkFiles as $idx => $chunkKey) {
            try {
                Log::info("Processing chunk (stream)", [
                    'export_id' => $this->exportId,
                    'chunk_key' => $chunkKey,
                    'index' => $idx + 1
                ]);

                // Open read stream from storage (works with local and remote)
                $stream = $disk->readStream($chunkKey);
                if ($stream === false) {
                    Log::warning("Could not open read stream for chunk", [
                        'export_id' => $this->exportId,
                        'chunk' => $chunkKey
                    ]);
                    continue;
                }

                // For the first file: copy fully
                if ($isFirst) {
                    while (!feof($stream)) {
                        $buffer = fread($stream, 8192);
                        if ($buffer === false) break;
                        fwrite($outHandle, $buffer);
                    }
                    // Count rows approximately (count newline chars) - used for logging
                    fseek($outHandle, 0, SEEK_END);
                    $isFirst = false;
                } else {
                    // For subsequent chunks: skip the first line (header)
                    $firstLineSkipped = false;
                    while (!feof($stream)) {
                        $line = fgets($stream);
                        if ($line === false) break;
                        if (!$firstLineSkipped) {
                            // skip header line
                            $firstLineSkipped = true;
                            continue;
                        }
                        fwrite($outHandle, $line);
                    }
                }

                // close chunk stream
                fclose($stream);

                $processedChunks++;
                // optionally trigger GC periodically
                if ($processedChunks % 20 === 0) {
                    gc_collect_cycles();
                }

                Log::info("Chunk streamed successfully", [
                    'export_id' => $this->exportId,
                    'chunk' => $chunkKey,
                    'processed_chunks' => $processedChunks
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to stream chunk", [
                    'export_id' => $this->exportId,
                    'chunk' => $chunkKey,
                    'error' => $e->getMessage()
                ]);
                // continue with other chunks
                continue;
            }
        }

        fclose($outHandle);

        if ($processedChunks === 0) {
            // Clean up tmp file
            @unlink($tmpFinal);
            throw new \Exception("No chunks were successfully processed");
        }

        // Push merged file to the configured disk (works with S3 / remote)
        $finalStream = fopen($tmpFinal, 'r');
        if ($finalStream === false) {
            @unlink($tmpFinal);
            throw new \Exception("Failed to open merged tempfile for upload");
        }

        $putResult = $disk->put($this->finalFilePath, $finalStream);

        // close local stream
        fclose($finalStream);
        // remove temp local copy
        @unlink($tmpFinal);

        if ($putResult === false) {
            throw new \Exception("Failed to save merged file to storage at {$this->finalFilePath}");
        }

        // Optionally get file size (only works reliably for local file or via metadata)
        try {
            $size = $disk->size($this->finalFilePath);
        } catch (\Throwable $e) {
            $size = null;
        }

        Log::info("Merged file saved to storage", [
            'export_id' => $this->exportId,
            'final_path' => $this->finalFilePath,
            'chunks' => $processedChunks,
            'size' => $size
        ]);

        // cleanup chunk files and temp dir using your existing method
        $this->cleanupTempFiles($disk, $chunkFiles);

        // mark completed in cache, reuse your markExportCompleted
        $this->markExportCompleted($totalRows);

        Log::info("=== MERGE JOB COMPLETED SUCCESSFULLY ===", [
            'export_id' => $this->exportId,
            'processed_chunks' => $processedChunks
        ]);
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
                            Log::info("Chunk file deleted", [
                                'export_id' => $this->exportId,
                                'file' => $chunkFile
                            ]);
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
                    } else {
                        Log::warning("Failed to delete temp directory", [
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
                'failed_deletes' => count($failedDeletes),
                'failed_files' => $failedDeletes
            ]);
        } catch (\Throwable $e) {
            Log::error("Cleanup process failed", [
                'export_id' => $this->exportId,
                'temp_dir' => $this->tempDir,
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

                $saved = Cache::put("export_{$this->exportId}", $exportData, now()->addHours(24));

                Log::info("Cache update attempted", [
                    'export_id' => $this->exportId,
                    'save_result' => $saved,
                    'new_status' => $exportData['status']
                ]);

                // Verify cache was updated
                sleep(1); // Brief pause
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
                        'actual_status' => $verifyCache['status'] ?? 'null',
                        'cache_data' => $verifyCache
                    ]);
                }
            } else {
                Log::error("Cannot mark as completed - cache data not found", [
                    'export_id' => $this->exportId,
                    'cache_key' => "export_{$this->exportId}"
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("Failed to mark export as completed", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("=== MERGE JOB FAILED PERMANENTLY ===", [
            'export_id' => $this->exportId,
            'temp_dir' => $this->tempDir,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        Cache::put("export_{$this->exportId}", [
            'status' => 'failed',
            'error_message' => "Export merge failed: " . $exception->getMessage(),
            'failed_at' => now()->toDateTimeString(),
        ], now()->addHours(24));
    }
}
