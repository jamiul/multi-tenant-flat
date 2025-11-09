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
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
            'full_temp_path' => $disk->path($this->tempDir)
        ]);

        // Check if temp directory exists
        if (!$disk->exists($this->tempDir)) {
            Log::error("Temp directory not found", [
                'export_id' => $this->exportId,
                'temp_dir' => $this->tempDir,
                'full_path' => $disk->path($this->tempDir)
            ]);
            throw new \Exception("Temp directory not found: {$this->tempDir}");
        }

        try {
            // Wait to ensure all files are fully written
            sleep(3);

            // Get all chunk files
            $chunkFiles = $disk->files($this->tempDir);

            Log::info("Scanning for chunk files", [
                'export_id' => $this->exportId,
                'temp_dir' => $this->tempDir,
                'found_files' => count($chunkFiles),
                'files' => $chunkFiles
            ]);

            if (empty($chunkFiles)) {
                // Try alternative path
                $altTempDir = $this->tempDir;
                $allFiles = $disk->allFiles('exports');

                Log::error("No chunk files found", [
                    'export_id' => $this->exportId,
                    'temp_dir' => $this->tempDir,
                    'disk_path' => $disk->path($this->tempDir),
                    'all_export_files' => $allFiles
                ]);

                throw new \Exception("No chunk files found in {$this->tempDir}");
            }

            // Filter only xlsx files
            $chunkFiles = array_filter($chunkFiles, function($file) {
                return str_ends_with($file, '.xlsx');
            });

            if (empty($chunkFiles)) {
                throw new \Exception("No .xlsx files found in {$this->tempDir}");
            }

            // Sort chunk files numerically
            usort($chunkFiles, function($a, $b) {
                preg_match('/chunk_(\d+)\.xlsx$/', $a, $matchA);
                preg_match('/chunk_(\d+)\.xlsx$/', $b, $matchB);
                return (int)($matchA[1] ?? 0) <=> (int)($matchB[1] ?? 0);
            });

            Log::info("Chunk files sorted and ready", [
                'export_id' => $this->exportId,
                'chunk_count' => count($chunkFiles),
                'sorted_files' => $chunkFiles
            ]);

            // Create merged spreadsheet
            $mergedSpreadsheet = new Spreadsheet();
            $mergedSheet = $mergedSpreadsheet->getActiveSheet();

            $currentRow = 1;
            $isFirstFile = true;
            $processedChunks = 0;

            foreach ($chunkFiles as $chunkFile) {
                try {
                    $fullPath = $disk->path($chunkFile);

                    if (!file_exists($fullPath)) {
                        Log::warning("Chunk file not found", [
                            'export_id' => $this->exportId,
                            'path' => $fullPath
                        ]);
                        continue;
                    }

                    if (!is_readable($fullPath)) {
                        Log::warning("Chunk file not readable", [
                            'export_id' => $this->exportId,
                            'path' => $fullPath
                        ]);
                        continue;
                    }

                    $fileSize = filesize($fullPath);
                    Log::info("Processing chunk", [
                        'export_id' => $this->exportId,
                        'file' => $chunkFile,
                        'size' => $fileSize,
                        'size_kb' => round($fileSize / 1024, 2),
                        'chunk_number' => $processedChunks + 1
                    ]);

                    // Load chunk
                    $reader = IOFactory::createReader('Xlsx');
                    $reader->setReadDataOnly(true);
                    $chunkSpreadsheet = $reader->load($fullPath);
                    $chunkSheet = $chunkSpreadsheet->getActiveSheet();

                    $highestRow = $chunkSheet->getHighestRow();

                    if ($highestRow < 1) {
                        Log::warning("Empty chunk file", [
                            'export_id' => $this->exportId,
                            'file' => $chunkFile
                        ]);
                        $chunkSpreadsheet->disconnectWorksheets();
                        unset($chunkSpreadsheet);
                        continue;
                    }

                    // Get data as array
                    $chunkData = $chunkSheet->toArray(null, true, true, true);

                    if ($isFirstFile) {
                        // First file: include headers
                        if (!empty($chunkData)) {
                            $mergedSheet->fromArray($chunkData, null, 'A' . $currentRow);
                            $currentRow += count($chunkData);
                        }
                        $isFirstFile = false;

                        Log::info("First chunk processed (with headers)", [
                            'export_id' => $this->exportId,
                            'rows_added' => count($chunkData),
                            'current_row' => $currentRow
                        ]);
                    } else {
                        // Skip header row for subsequent files
                        array_shift($chunkData);
                        if (!empty($chunkData)) {
                            $mergedSheet->fromArray($chunkData, null, 'A' . $currentRow);
                            $currentRow += count($chunkData);
                        }

                        Log::info("Chunk processed (without header)", [
                            'export_id' => $this->exportId,
                            'rows_added' => count($chunkData),
                            'current_row' => $currentRow
                        ]);
                    }

                    // Cleanup
                    $chunkSpreadsheet->disconnectWorksheets();
                    unset($chunkSpreadsheet, $chunkData);

                    if ($processedChunks % 10 === 0) {
                        gc_collect_cycles();
                    }

                    $processedChunks++;

                } catch (\Throwable $e) {
                    Log::error("Failed to process chunk", [
                        'export_id' => $this->exportId,
                        'file' => $chunkFile,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }
            }

            if ($processedChunks === 0) {
                throw new \Exception("No chunks were successfully processed");
            }

            Log::info("All chunks merged, preparing to write file", [
                'export_id' => $this->exportId,
                'processed_chunks' => $processedChunks,
                'total_rows' => $currentRow - 1
            ]);

            // Ensure export directory exists
            $exportDir = dirname($disk->path($this->finalFilePath));
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
                Log::info("Created export directory", [
                    'export_id' => $this->exportId,
                    'path' => $exportDir
                ]);
            }

            // Write merged file
            $writer = new Xlsx($mergedSpreadsheet);
            $writer->setPreCalculateFormulas(false);
            $finalPath = $disk->path($this->finalFilePath);

            Log::info("Writing merged file to disk", [
                'export_id' => $this->exportId,
                'path' => $finalPath,
                'total_rows' => $currentRow - 1
            ]);

            $writer->save($finalPath);

            // Verify file was created
            if (!file_exists($finalPath)) {
                throw new \Exception("Failed to write merged file to disk");
            }

            $fileSize = filesize($finalPath);
            Log::info("Merged file written successfully", [
                'export_id' => $this->exportId,
                'path' => $finalPath,
                'size' => $fileSize,
                'size_mb' => round($fileSize / 1048576, 2)
            ]);

            // Cleanup memory
            $mergedSpreadsheet->disconnectWorksheets();
            unset($mergedSpreadsheet, $writer);
            gc_collect_cycles();

            // Clean up chunk files and temp directory
            $this->cleanupTempFiles($disk, $chunkFiles);

            // Mark export as completed
            $totalRows = $currentRow - 1;
            $this->markExportCompleted($totalRows);

            Log::info("=== MERGE JOB COMPLETED SUCCESSFULLY ===", [
                'export_id' => $this->exportId,
                'total_rows' => $totalRows,
                'file_size_mb' => round($fileSize / 1048576, 2),
                'processed_chunks' => $processedChunks
            ]);

        } catch (\Throwable $e) {
            Log::error("=== MERGE JOB FAILED ===", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts()
            ]);

            Cache::put("export_{$this->exportId}", [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at' => now()->toDateTimeString(),
            ], now()->addHours(24));

            throw $e;
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
                $exportData['file_url'] = Storage::disk('public')->url($this->finalFilePath);

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