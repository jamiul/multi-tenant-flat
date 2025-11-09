<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Notifications\ExportReady;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 3;
    public $maxExceptions = 3;

    protected $user;
    protected $fileName;
    protected $exportId;

    /**
     * Create a new job instance.
     */
    public function __construct(User $user, string $fileName, string $exportId)
    {
        $this->user = $user;
        $this->fileName = $fileName;
        $this->exportId = $exportId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Starting export for user {$this->user->id}", [
                'export_id' => $this->exportId,
                'file_name' => $this->fileName
            ]);

            // Create spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Set headers
            $headers = [
                'ID',
                'First Name',
                'Last Name',
                'Email',
                'Phone',
                'Address',
                'City',
                'State',
                'Zip Code',
                'Country',
            ];
            $sheet->fromArray($headers, null, 'A1');

            // Style headers
            $sheet->getStyle('A1:J1')->getFont()->setBold(true);

            // Process in chunks for memory efficiency
            $chunkSize = 1000;
            $row = 2;
            $totalRecords = Customer::count();
            $processedRecords = 0;

            Log::info("Total records to export: {$totalRecords}", [
                'export_id' => $this->exportId
            ]);

            Customer::select([
                'id',
                'first_name',
                'last_name',
                'email',
                'phone',
                'address',
                'city',
                'state',
                'zip_code',
                'country',
            ])->chunk($chunkSize, function ($customers) use ($sheet, &$row, &$processedRecords, $totalRecords) {
                foreach ($customers as $customer) {
                    $sheet->fromArray([
                        $customer->id,
                        $customer->first_name,
                        $customer->last_name,
                        $customer->email,
                        $customer->phone,
                        $customer->address,
                        $customer->city,
                        $customer->state,
                        $customer->zip_code,
                        $customer->country,
                    ], null, 'A' . $row);
                    $row++;
                    $processedRecords++;
                }

                // Calculate progress
                $progress = ($processedRecords / $totalRecords) * 100;
                
                // Only update cache every 5% or on first/last chunk to reduce Redis load
                $roundedProgress = floor($progress / 5) * 5;
                static $lastUpdateProgress = -1;
                
                if ($roundedProgress != $lastUpdateProgress || $processedRecords === $totalRecords) {
                    $this->updateProgress($progress);
                    $lastUpdateProgress = $roundedProgress;
                    
                    Log::info("Export progress: {$progress}%", [
                        'export_id' => $this->exportId,
                        'processed' => $processedRecords,
                        'total' => $totalRecords,
                        'rounded' => $roundedProgress
                    ]);
                }
            });

            // Auto-size columns
            foreach (range('A', 'J') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Save to storage
            $fullPath = storage_path('app/public/' . $this->fileName);

            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            Log::info("Saving Excel file", [
                'export_id' => $this->exportId,
                'path' => $fullPath
            ]);

            $writer = new Xlsx($spreadsheet);
            $writer->save($fullPath);

            // Free memory
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            // Verify file was created
            if (!file_exists($fullPath)) {
                throw new \Exception("Export file was not created at path: {$fullPath}");
            }

            $fileSize = filesize($fullPath);
            
            Log::info("Excel file saved successfully", [
                'export_id' => $this->exportId,
                'path' => $fullPath,
                'size' => $fileSize,
                'size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            // **CRITICAL: Update cache with completed status**
            $completionData = [
                'user_id' => $this->user->id,
                'file_name' => $this->fileName,
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now()->toDateTimeString(),
                'started_at' => Cache::get("export_{$this->exportId}")['started_at'] ?? now()->toDateTimeString(),
            ];

            $saved = Cache::put("export_{$this->exportId}", $completionData, now()->addHours(24));

            if (!$saved) {
                Log::error("Failed to update cache with completion status", [
                    'export_id' => $this->exportId
                ]);
            }

            // Verify cache was updated
            $verification = Cache::get("export_{$this->exportId}");
            
            Log::info("Export completed successfully", [
                'export_id' => $this->exportId,
                'file_name' => $this->fileName,
                'records' => $processedRecords,
                'cache_updated' => $saved,
                'verified_status' => $verification['status'] ?? 'unknown'
            ]);

            // Send notification
            try {
                $this->user->notify(new ExportReady($this->fileName));
            } catch (\Exception $e) {
                Log::warning("Failed to send notification", [
                    'export_id' => $this->exportId,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Export failed: " . $e->getMessage(), [
                'export_id' => $this->exportId,
                'user_id' => $this->user->id,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update cache with failure status
            Cache::put("export_{$this->exportId}", [
                'user_id' => $this->user->id,
                'file_name' => $this->fileName,
                'status' => 'failed',
                'progress' => 0,
                'error_message' => $e->getMessage(),
                'failed_at' => now()->toDateTimeString(),
            ], now()->addHours(24));

            throw $e;
        }
    }

    /**
     * Update export progress in cache with retry logic
     */
    protected function updateProgress(float $progress): void
    {
        try {
            $exportData = Cache::get("export_{$this->exportId}");
            
            if (!$exportData) {
                Log::warning("Export data not found in cache during progress update", [
                    'export_id' => $this->exportId
                ]);
                
                // Recreate cache entry if missing
                $exportData = [
                    'user_id' => $this->user->id,
                    'file_name' => $this->fileName,
                    'started_at' => now()->toDateTimeString(),
                ];
            }
            
            // Update progress and status
            $exportData['progress'] = round($progress, 2);
            $exportData['status'] = 'processing';
            $exportData['last_updated'] = now()->toDateTimeString();
            
            // Save with retry
            $maxRetries = 3;
            $saved = false;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $saved = Cache::put("export_{$this->exportId}", $exportData, now()->addHours(24));
                
                if ($saved) {
                    break;
                }
                
                if ($attempt < $maxRetries) {
                    usleep(100000 * $attempt); // 100ms, 200ms, 300ms
                }
            }
            
            if (!$saved) {
                Log::error("Failed to save progress to cache after {$maxRetries} attempts", [
                    'export_id' => $this->exportId,
                    'progress' => $progress
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error("Error updating progress in cache", [
                'export_id' => $this->exportId,
                'progress' => $progress,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Export job failed permanently", [
            'export_id' => $this->exportId,
            'user_id' => $this->user->id,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);

        Cache::put("export_{$this->exportId}", [
            'user_id' => $this->user->id,
            'file_name' => $this->fileName,
            'status' => 'failed',
            'progress' => 0,
            'error_message' => $exception->getMessage(),
            'failed_at' => now()->toDateTimeString(),
        ], now()->addHours(24));
    }
}