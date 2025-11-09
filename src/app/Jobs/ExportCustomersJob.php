<?php

namespace App\Jobs;

use App\Exports\CustomersExport;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ExportCustomersJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $filePath;
    public $offset;
    public $limit;

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
        Excel::store(new CustomersExport($this->offset, $this->limit), $this->filePath, 'public');
    }
}
