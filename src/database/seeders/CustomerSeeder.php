<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
// We no longer need File, DB, or CSV Writer
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\File;
// use League\Csv\Writer;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $totalRecords = 1000000;
        // 5,000 is a good chunk size for DB inserts as well
        $chunkSize = 5000;

        // All CSV and File logic has been removed
        $this->command->info("Inserting $totalRecords customer records directly into the database...");

        // Progress bar setup
        $this->command->getOutput()->progressStart($totalRecords);

        // Calculate the number of chunks
        $chunks = ceil($totalRecords / $chunkSize);

        for ($i = 0; $i < $chunks; $i++) {
            // Determine how many records to generate in this chunk
            // This handles the final chunk, which might be smaller
            $recordsToGenerate = min($chunkSize, $totalRecords - ($i * $chunkSize));

            if ($recordsToGenerate <= 0) {
                break; // Should not happen, but good to have
            }

            // This one line replaces the `make()`, the `foreach` loop,
            // the `$records` array, and the `csv->insertAll()`.
            // The `create()` method generates and inserts records into the DB.
            Customer::factory()->count($recordsToGenerate)->create();

            // Write all records for this chunk to the CSV
            $this->command->getOutput()->progressAdvance($recordsToGenerate);
        }

        $this->command->getOutput()->progressFinish();
        $this->command->info('Customer records inserted successfully.');

        // All CSV import and file deletion logic has been removed
    }

    // The importCsvToDb method is no longer needed
}

