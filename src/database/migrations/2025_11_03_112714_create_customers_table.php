<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 150)->unique();
            $table->string('phone', 20);
            $table->text('address');
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('zip_code', 20);
            $table->string('country', 100);
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->tinyInteger('status')->default(1)->comment('0=inactive, 1=active');
            $table->tinyInteger('customer_type')->default(1)->comment('1=regular, 2=premium, 3=vip');
            $table->date('registration_date');
            $table->timestamps();

            // Add indexes for better query performance
            $table->index('email');
            $table->index('customer_type');
            $table->index('status');
            $table->index('registration_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
