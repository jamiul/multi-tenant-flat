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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class MergeExportFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tempDir;
    public $finalFilePath;
    public $userId;
    public $exportId;

    public $timeout = 3600; // 60 minutes - increased significantly
    public $tries = 3;
    public $maxExceptions = 3;
    public $backoff = 30;

    // Memory limit for the job
    public $memory = '2048M'; // 2GB - adjust based on your server

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
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]);

        // Increase PHP memory and execution limits
        ini_set('memory_limit', '2048M');
        ini_set('max_execution_time', '3600');

        if (!$disk->exists($this->tempDir)) {
            throw new \Exception("Temp directory not found: {$this->tempDir}");
        }

        // 1️⃣  Collect & sort chunk CSVs
        $chunkFiles = array_values(array_filter($disk->files($this->tempDir), fn($f) => str_ends_with($f, '.csv')));

        usort($chunkFiles, function ($a, $b) {
            preg_match('/chunk_(\d+)\.csv$/', $a, $ma);
            preg_match('/chunk_(\d+)\.csv$/', $b, $mb);
            return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
        });

        if (empty($chunkFiles)) {
            throw new \Exception("No CSV chunk files found in {$this->tempDir}");
        }

        Log::info("Found chunk files", [
            'export_id' => $this->exportId,
            'chunk_count' => count($chunkFiles)
        ]);

        // 2️⃣  Merge CSV chunks into one local temp CSV
        $tmpCsv = sys_get_temp_dir() . "/merged_export_{$this->exportId}_" . uniqid() . ".csv";
        $totalRows = $this->mergeChunks($disk, $chunkFiles, $tmpCsv);

        if ($totalRows === 0) {
            @unlink($tmpCsv);
            throw new \Exception("No data was processed from chunks");
        }

        // 3️⃣  Convert merged CSV → XLSX with optimizations
        try {
            $this->updateProgress(50, "Converting to Excel format...");
            
            $xlsxTmp = sys_get_temp_dir() . "/final_export_{$this->exportId}.xlsx";
            $this->convertCsvToXlsxOptimized($tmpCsv, $xlsxTmp);

            // Upload final XLSX to Laravel storage
            $this->updateProgress(90, "Uploading final file...");
            
            $xlsxStream = fopen($xlsxTmp, 'r');
            if (!$xlsxStream) {
                throw new \Exception("Failed to open XLSX file for upload");
            }
            
            $disk->put($this->finalFilePath, $xlsxStream);
            fclose($xlsxStream);

            // Remove temp files
            @unlink($tmpCsv);
            @unlink($xlsxTmp);

            Log::info("Merged XLSX created successfully", [
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
            @unlink($tmpCsv);
            throw $e;
        }

        // 4️⃣  Cleanup chunk files & mark as completed
        $this->cleanupTempFiles($disk, $chunkFiles);
        $this->markExportCompleted($totalRows);

        Log::info("=== MERGE JOB COMPLETED SUCCESSFULLY ===", [
            'export_id' => $this->exportId,
            'total_rows' => $totalRows,
            'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB'
        ]);
    }

    private function mergeChunks($disk, array $chunkFiles, string $tmpCsv): int
    {
        $out = fopen($tmpCsv, 'w');
        if (!$out) {
            throw new \Exception("Failed to create temp file: {$tmpCsv}");
        }

        $isFirst = true;
        $processedChunks = 0;
        $totalRows = 0;

        Log::info("Starting chunk merge", [
            'export_id' => $this->exportId,
            'total_chunks' => count($chunkFiles)
        ]);

        foreach ($chunkFiles as $index => $chunkKey) {
            $stream = $disk->readStream($chunkKey);
            if (!$stream) {
                Log::warning("Cannot read stream for chunk", ['chunk' => $chunkKey]);
                continue;
            }

            $headerSkipped = false;
            $chunkRows = 0;

            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line === false) break;
                
                if (!$isFirst && !$headerSkipped) {
                    // Skip header row for all chunks after first
                    $headerSkipped = true;
                    continue;
                }
                
                fwrite($out, $line);
                $chunkRows++;
            }

            fclose($stream);
            $totalRows += $chunkRows;
            $isFirst = false;
            $processedChunks++;

            // Update progress every few chunks
            if ($processedChunks % 5 === 0) {
                $progress = (int)(($processedChunks / count($chunkFiles)) * 40); // 0-40% for merging
                $this->updateProgress($progress, "Merging chunks... ({$processedChunks}/" . count($chunkFiles) . ")");
            }

            Log::info("Chunk merged", [
                'export_id' => $this->exportId,
                'chunk' => $index + 1,
                'total' => count($chunkFiles),
                'rows' => $chunkRows
            ]);
        }

        fclose($out);

        Log::info("CSV chunks merged successfully", [
            'export_id' => $this->exportId,
            'merged_temp' => $tmpCsv,
            'chunks' => $processedChunks,
            'total_rows' => $totalRows
        ]);

        return $totalRows;
    }

    private function convertCsvToXlsxOptimized(string $csvPath, string $xlsxPath): void
    {
        Log::info("Starting CSV to XLSX conversion", [
            'export_id' => $this->exportId,
            'csv_path' => $csvPath,
            'file_size' => round(filesize($csvPath) / 1024 / 1024, 2) . 'MB'
        ]);

        // Method 1: Use PhpSpreadsheet with optimizations
        $reader = IOFactory::createReader('Csv');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        
        // Configure CSV reader for better performance
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);

        Log::info("Loading CSV file...", ['export_id' => $this->exportId]);
        $spreadsheet = $reader->load($csvPath);
        
        Log::info("CSV loaded, creating XLSX writer...", ['export_id' => $this->exportId]);
        
        // Configure XLSX writer for performance
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        
        // Optional: Disable auto-sizing for faster writing
        // $writer->getDefaultStyle()->getAlignment()->setWrapText(false);
        
        Log::info("Saving XLSX file...", ['export_id' => $this->exportId]);
        $writer->save($xlsxPath);

        // Clean up spreadsheet object immediately
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer, $reader);
        
        // Force garbage collection
        gc_collect_cycles();

        Log::info("XLSX conversion completed", [
            'export_id' => $this->exportId,
            'xlsx_size' => round(filesize($xlsxPath) / 1024 / 1024, 2) . 'MB'
        ]);
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