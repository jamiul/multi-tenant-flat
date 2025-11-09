<?php

namespace App\Exports;

use App\Models\Customer;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CustomersExport implements FromQuery, WithHeadings, WithMapping
{
    protected $offset;
    protected $limit;

    public function __construct($offset, $limit)
    {
        $this->offset = $offset;
        $this->limit = $limit;
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
        ])->offset($this->offset)->limit($this->limit);
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
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'First Name',
            'Last Name',
            'Email',
            'Phone',
            'Address',
            'City',
            'State',
            'Zip Code',
            'Country',
        ];
    }

}
