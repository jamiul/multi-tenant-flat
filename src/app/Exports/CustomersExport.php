<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison; // Added for strict null comparison

class CustomersExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithEvents, WithStrictNullComparison
{
    use Exportable;

    protected $fileName;

    public function __construct(string $fileName)
    {
        $this->fileName = $fileName;
    }

    public function query()
    {
           return Customer::query()->select([
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
            DB::raw("DATE_FORMAT(date_of_birth, '%Y-%m-%d') as formatted_date_of_birth"), // MySQL date format
            'gender',
            'status',
            'customer_type',
            DB::raw("DATE_FORMAT(registration_date, '%Y-%m-%d %H:%i:%s') as formatted_registration_date"), // MySQL datetime format
        ]);
    }

    public function headings(): array
    {
        return [
            'ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Address',
            'City', 'State', 'Zip Code', 'Country', 'Date of Birth',
            'Gender', 'Status', 'Customer Type', 'Registration Date',
        ];
    }

    public function map($customer): array
    {
        return [
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
            $customer->formatted_date_of_birth,
            ucfirst($customer->gender ?? 'N/A'),
            $customer->status ? 'Active' : 'Inactive',
            $this->getCustomerTypeName($customer->customer_type),
            $customer->formatted_registration_date,
        ];
    }

    protected function getCustomerTypeName(?int $type): string
    {
        return match ($type) {
            1 => 'Regular',
            2 => 'Premium',
            3 => 'Enterprise',
            default => 'Unknown',
        };
    }

    public function chunkSize(): int
    {
        return 5000;
    }

    // This is required for WithEvents
    public function registerEvents(): array
    {
        return [
            // Optional: do something after the sheet is created
            AfterSheet::class => function(AfterSheet $event) {
                // You can style the sheet here if needed
            },
        ];
    }

    // Force storage path
    // public function store($disk = null, $writerType = null)
    // {
    //     return $this->exportable->store($this->fileName, 'public');
    // }
}