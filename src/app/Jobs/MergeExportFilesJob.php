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
    
    // Add timeout and retry configuration
    public $timeout = 600; // 10 minutes
    public $tries = 3;
    public $maxExceptions = 3;
    public $backoff = 30; // Wait 30 seconds between retries

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
        
        try {
            // Wait a moment to ensure all chunk files are fully written
            sleep(2);
            
            // Get all chunk files
            $chunkFiles = $disk->files($this->tempDir);
            
            if (empty($chunkFiles)) {
                throw new \Exception("No chunk files found in {$this->tempDir}");
            }

            // Sort chunk files numerically by extracting the chunk number
            usort($chunkFiles, function($a, $b) {
                preg_match('/chunk_(\d+)\.xlsx$/', $a, $matchA);
                preg_match('/chunk_(\d+)\.xlsx$/', $b, $matchB);
                return (int)($matchA[1] ?? 0) <=> (int)($matchB[1] ?? 0);
            });

            Log::info("Merging export chunks", [
                'temp_dir' => $this->tempDir,
                'chunk_count' => count($chunkFiles),
                'final_path' => $this->finalFilePath,
                'chunks' => $chunkFiles
            ]);

            // Create a new spreadsheet for the merged data
            $mergedSpreadsheet = new Spreadsheet();
            $mergedSheet = $mergedSpreadsheet->getActiveSheet();
            
            $currentRow = 1;
            $isFirstFile = true;
            $processedChunks = 0;

            foreach ($chunkFiles as $chunkFile) {
                try {
                    $fullPath = $disk->path($chunkFile);

                    // Verify file exists and is readable
                    if (!file_exists($fullPath)) {
                        Log::warning("Chunk file not found", ['path' => $fullPath]);
                        continue;
                    }

                    if (!is_readable($fullPath)) {
                        Log::warning("Chunk file not readable", ['path' => $fullPath]);
                        continue;
                    }

                    Log::info("Processing chunk", [
                        'file' => $chunkFile,
                        'size' => filesize($fullPath)
                    ]);

                    // Load the chunk file with memory optimization
                    $reader = IOFactory::createReader('Xlsx');
                    $reader->setReadDataOnly(true);
                    $chunkSpreadsheet = $reader->load($fullPath);
                    $chunkSheet = $chunkSpreadsheet->getActiveSheet();

                    // Get highest row to avoid loading empty rows
                    $highestRow = $chunkSheet->getHighestRow();
                    
                    if ($highestRow < 1) {
                        Log::warning("Empty chunk file", ['file' => $chunkFile]);
                        $chunkSpreadsheet->disconnectWorksheets();
                        unset($chunkSpreadsheet);
                        continue;
                    }

                    // Get all rows as an array
                    $chunkData = $chunkSheet->toArray(null, true, true, true);

                    if ($isFirstFile) {
                        // For the first file, copy all rows (including headers)
                        if (!empty($chunkData)) {
                            $mergedSheet->fromArray($chunkData, null, 'A' . $currentRow);
                            $currentRow += count($chunkData);
                        }
                        $isFirstFile = false;
                    } else {
                        // For subsequent files, skip the header row
                        array_shift($chunkData);
                        if (!empty($chunkData)) {
                            $mergedSheet->fromArray($chunkData, null, 'A' . $currentRow);
                            $currentRow += count($chunkData);
                        }
                    }

                    // Clean up chunk spreadsheet from memory
                    $chunkSpreadsheet->disconnectWorksheets();
                    unset($chunkSpreadsheet, $chunkData);
                    
                    // Force garbage collection for large datasets
                    if ($processedChunks % 10 === 0) {
                        gc_collect_cycles();
                    }
                    
                    $processedChunks++;

                    Log::info("Chunk processed", [
                        'file' => $chunkFile,
                        'current_row' => $currentRow,
                        'processed' => $processedChunks
                    ]);

                } catch (\Throwable $e) {
                    Log::error("Failed to process chunk", [
                        'file' => $chunkFile,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with next chunk instead of failing entirely
                    continue;
                }
            }

            if ($processedChunks === 0) {
                throw new \Exception("No chunks were successfully processed");
            }

            // Ensure export directory exists
            $exportDir = dirname($disk->path($this->finalFilePath));
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            // Save the merged file with write optimization
            $writer = new Xlsx($mergedSpreadsheet);
            $writer->setPreCalculateFormulas(false);
            $finalPath = $disk->path($this->finalFilePath);
            
            Log::info("Writing merged file", [
                'path' => $finalPath,
                'total_rows' => $currentRow - 1
            ]);
            
            $writer->save($finalPath);
            
            // Verify file was created
            if (!file_exists($finalPath)) {
                throw new \Exception("Failed to write merged file to disk");
            }

            Log::info("Merged file written successfully", [
                'path' => $finalPath,
                'size' => filesize($finalPath)
            ]);
            
            // Clean up merged spreadsheet from memory
            $mergedSpreadsheet->disconnectWorksheets();
            unset($mergedSpreadsheet, $writer);
            gc_collect_cycles();

            // Clean up chunk files and temp directory
            $this->cleanupTempFiles($disk, $chunkFiles);

            // Update cache to mark export as completed
            $this->markExportCompleted($currentRow - 1);

        } catch (\Throwable $e) {
            Log::error("Export merge failed", [
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempt' => $this->attempts()
            ]);

            // Update cache to mark export as failed
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
            foreach ($chunkFiles as $chunkFile) {
                if ($disk->exists($chunkFile)) {
                    $disk->delete($chunkFile);
                }
            }
            
            if ($disk->exists($this->tempDir)) {
                $disk->deleteDirectory($this->tempDir);
            }

            Log::info("Cleanup completed", [
                'temp_dir' => $this->tempDir,
                'chunks_deleted' => count($chunkFiles)
            ]);
        } catch (\Throwable $e) {
            Log::warning("Cleanup failed but export succeeded", [
                'temp_dir' => $this->tempDir,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function markExportCompleted(int $totalRows): void
    {
        $exportData = Cache::get("export_{$this->exportId}");
        if ($exportData) {
            $exportData['status'] = 'completed';
            $exportData['progress'] = 100;
            $exportData['completed_at'] = now()->toDateTimeString();
            $exportData['total_rows'] = $totalRows;
            $exportData['file_url'] = Storage::disk('public')->url($this->finalFilePath);
            
            Cache::put("export_{$this->exportId}", $exportData, now()->addHours(24));

            Log::info("Export marked as completed", [
                'export_id' => $this->exportId,
                'final_path' => $this->finalFilePath,
                'total_rows' => $totalRows
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Export merge job failed permanently", [
            'export_id' => $this->exportId,
            'temp_dir' => $this->tempDir,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Mark as failed in cache
        Cache::put("export_{$this->exportId}", [
            'status' => 'failed',
            'error_message' => "Export merge failed: " . $exception->getMessage(),
            'failed_at' => now()->toDateTimeString(),
        ], now()->addHours(24));
    }
}