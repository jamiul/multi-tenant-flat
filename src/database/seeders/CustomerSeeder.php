<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
// We no longer need File, DB, or CSV Writer
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Csv\Writer;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $totalRecords = 10000;
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

    /**
     * Import CSV to database using chunked inserts.
     */
    protected function importCsvToDb(string $path): void
    {
        $this->command->info('Importing CSV to database...');

        $file = fopen($path, 'r');

        // Skip header row
        fgetcsv($file);

        $chunkSize = 1000;
        $records = [];

        $this->command->getOutput()->progressStart(10000);

        while (($data = fgetcsv($file)) !== false) {
            $records[] = [
                'first_name' => $data[0],
                'last_name' => $data[1],
                'email' => $data[2],
                'phone' => $data[3],
                'address' => $data[4],
                'city' => $data[5],
                'state' => $data[6],
                'zip_code' => $data[7],
                'country' => $data[8],
                'date_of_birth' => $data[9],
                'gender' => $data[10],
                'status' => $data[11],
                'customer_type' => $data[12],
                'registration_date' => $data[13],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($records) >= $chunkSize) {
                DB::table('customers')->insert($records);
                $records = [];
                $this->command->getOutput()->progressAdvance($chunkSize);
            }
        }

        // Insert remaining records
        if (!empty($records)) {
            DB::table('customers')->insert($records);
            $this->command->getOutput()->progressAdvance(count($records));
        }

        fclose($file);
        $this->command->getOutput()->progressFinish();
        $this->command->info('CSV imported successfully.');
    }
}