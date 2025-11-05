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
<<<<<<< HEAD
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

=======
        // $totalRecords = 1000;
        // $chunkSize = 100;
        $csvPath = storage_path('app/customers.csv');

        // // Create a CSV writer instance
        // $csv = Writer::createFromPath($csvPath, 'w+');
        // $csv->insertOne([
        //     'first_name', 'last_name', 'email', 'phone', 'address', 'city', 'state',
        //     'zip_code', 'country', 'date_of_birth', 'gender', 'status',
        //     'customer_type', 'registration_date', 'created_at', 'updated_at'
        // ]);

        // $this->command->getOutput()->progressStart($totalRecords);

        // for ($i = 0; $i < $totalRecords; $i += $chunkSize) {
        //     $customers = Customer::factory()->count($chunkSize)->make();
        //     $records = [];
        //     foreach ($customers as $customer) {
        //         $records[] = [
        //             $customer->first_name,
        //             $customer->last_name,
        //             $customer->email,
        //             $customer->phone,
        //             $customer->address,
        //             $customer->city,
        //             $customer->state,
        //             $customer->zip_code,
        //             $customer->country,
        //             $customer->date_of_birth,
        //             $customer->gender,
        //             $customer->status,
        //             $customer->customer_type,
        //             $customer->registration_date,
        //             now()->toDateTimeString(),
        //             now()->toDateTimeString(),
        //         ];
        //     }
        //     $csv->insertAll($records);
        //     $this->command->getOutput()->progressAdvance($chunkSize);
        // }

        // $this->command->getOutput()->progressFinish();
        // $this->command->info('CSV file generated successfully.');

        // Use chunked insert instead of LOAD DATA INFILE
        $this->importCsvToDb($csvPath);

        // Clean up the CSV file
        // File::delete($csvPath);
        // $this->command->info('CSV file deleted.');
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
                'created_at' => $data[14],
                'updated_at' => $data[15],
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
>>>>>>> d59c4406cc85b0a047a59e18285b15e6b612205d
