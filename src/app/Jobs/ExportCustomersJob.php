<?php

namespace App\Jobs;

use App\Exports\CustomersExport;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ExportCustomersJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $filePath;
    public $offset;
    public $limit;
    public $timeout = 300; // 5 minutes timeout
    public $tries = 3; // Retry up to 3 times

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($filePath, $offset, $limit)
    {
        $this->filePath = $filePath;
        $this->offset = $offset;
        $this->limit = $limit;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Check if batch has been cancelled
        if ($this->batch()->cancelled()) {
            Log::info("Job cancelled", [
                'file_path' => $this->filePath,
                'offset' => $this->offset
            ]);
            return;
        }

        try {
            Log::info("Exporting chunk", [
                'file_path' => $this->filePath,
                'offset' => $this->offset,
                'limit' => $this->limit
            ]);

            Excel::store(
                new CustomersExport($this->offset, $this->limit), 
                $this->filePath, 
                'public'
            );

            Log::info("Chunk exported successfully", [
                'file_path' => $this->filePath,
                'offset' => $this->offset
            ]);

        } catch (\Throwable $e) {
            Log::error("Failed to export chunk", [
                'file_path' => $this->filePath,
                'offset' => $this->offset,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error("Export job failed permanently", [
            'file_path' => $this->filePath,
            'offset' => $this->offset,
            'error' => $exception->getMessage()
        ]);
    }
}