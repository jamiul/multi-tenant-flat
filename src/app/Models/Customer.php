<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customer extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip_code',
        'country',
        'date_of_birth',
        'gender',
        'status',
        'customer_type',
        'registration_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
        'registration_date' => 'date',
        'status' => 'integer',
        'customer_type' => 'integer',
    ];

    /**
     * Scope a query to only include active customers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    /**
     * Scope a query to only include inactive customers.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 0);
    }

    /**
     * Scope a query by customer type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('customer_type', $type);
    }

    /**
     * Get the customer's full name.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get customer type name.
     */
    public function getCustomerTypeNameAttribute(): string
    {
        return match($this->customer_type) {
            1 => 'Regular',
            2 => 'Premium',
            3 => 'Enterprise',
            default => 'Unknown',
        };
    }
}
