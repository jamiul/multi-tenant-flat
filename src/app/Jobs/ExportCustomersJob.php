<?php

namespace App\Jobs;

use App\Models\Customer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

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
            Log::info("Exporting chunk with FastExcel", [
                'file_path' => $this->filePath,
                'offset' => $this->offset,
                'limit' => $this->limit
            ]);

            // Fetch customers for this chunk
            $customers = Customer::query()
                ->select([
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
                ])
                ->offset($this->offset)
                ->limit($this->limit)
                ->get();

            // Get the full storage path
            $disk = Storage::disk('public');
            $fullPath = $disk->path($this->filePath);

            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Export using FastExcel
            // Map the data to arrays for export
            $exportData = $customers->map(function ($customer) {
                return [
                    'ID' => $customer->id,
                    'First Name' => $customer->first_name,
                    'Last Name' => $customer->last_name,
                    'Email' => $customer->email,
                    'Phone' => $customer->phone,
                    'Address' => $customer->address,
                    'City' => $customer->city,
                    'State' => $customer->state,
                    'Zip Code' => $customer->zip_code,
                    'Country' => $customer->country,
                ];
            });

            // Export to CSV using FastExcel
            (new FastExcel($exportData))->export($fullPath);

            Log::info("Chunk exported successfully with FastExcel", [
                'file_path' => $this->filePath,
                'offset' => $this->offset,
                'records_count' => $customers->count()
            ]);

        } catch (\Throwable $e) {
            Log::error("Failed to export chunk with FastExcel", [
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