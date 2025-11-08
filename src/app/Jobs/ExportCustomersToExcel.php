<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable; // This job will be batchable
use App\Exports\CustomersExport;
use Maatwebsite\Excel\Facades\Excel; // Import Excel facade

class ExportCustomersToExcel implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $fileName;
    public $timeout = 3600; // Increase timeout for potentially long-running exports
    public $tries = 3; // Retry failed exports

    /**
     * Create a new job instance.
     *
     * @param string $fileName The full path where the file should be stored
     * @return void
     */
    public function __construct(string $fileName)
    {
        $this->fileName = $fileName;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Check if the batch was cancelled
        if ($this->batch()->cancelled()) {
            return;
        }

        // Perform the actual Excel export
        // The CustomersExport class itself implements FromQuery and WithChunkReading,
        // so Laravel Excel will handle the chunking internally.
        Excel::store(new CustomersExport($this->fileName), $this->fileName, 'public');
    }
}