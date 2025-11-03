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
        $totalRecords = 1000000;
        $chunkSize = 50000;
        $csvPath = storage_path('app/customers.csv');

        // Create a CSV writer instance
        $csv = Writer::createFromPath($csvPath, 'w+');
        $csv->insertOne([
            'first_name', 'last_name', 'email', 'phone', 'address', 'city', 'state',
            'zip_code', 'country', 'date_of_birth', 'gender', 'status',
            'customer_type', 'registration_date', 'created_at', 'updated_at'
        ]);

        $this->command->getOutput()->progressStart($totalRecords);

        for ($i = 0; $i < $totalRecords; $i += $chunkSize) {
            $customers = Customer::factory()->count($chunkSize)->make();
            $records = [];
            foreach ($customers as $customer) {
                $records[] = [
                    $customer->first_name,
                    $customer->last_name,
                    $customer->email,
                    $customer->phone,
                    $customer->address,
                    $customer->city,
                    $customer->state,
                    $customer->zip_code,
                    $customer->country,
                    $customer->date_of_birth,
                    $customer->gender,
                    $customer->status,
                    $customer->customer_type,
                    $customer->registration_date,
                    now()->toDateTimeString(),
                    now()->toDateTimeString(),
                ];
            }
            $csv->insertAll($records);
            $this->command->getOutput()->progressAdvance($chunkSize);
        }

        $this->command->getOutput()->progressFinish();
        $this->command->info('CSV file generated successfully.');

        // Use LOAD DATA INFILE for fast import
        $this->importCsvToDb($csvPath);

        // Clean up the CSV file
        File::delete($csvPath);
        $this->command->info('CSV file deleted.');
    }

    /**
     * Import CSV to database using LOAD DATA INFILE.
     */
    protected function importCsvToDb(string $path): void
    {
        $this->command->info('Importing CSV to database...');
        DB::connection()->getpdo()->exec("
            LOAD DATA LOCAL INFILE '" . $path . "'
            INTO TABLE customers
            FIELDS TERMINATED BY ','
            ENCLOSED BY '\"'
            LINES TERMINATED BY '\\n'
            IGNORE 1 ROWS
            (first_name, last_name, email, phone, address, city, state, zip_code, country, date_of_birth, gender, status, customer_type, registration_date, created_at, updated_at)
        ");
        $this->command->info('CSV imported successfully.');
    }
}
