<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;
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