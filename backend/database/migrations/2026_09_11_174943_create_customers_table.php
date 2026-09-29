<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // The document is the customer's business identity: a CPF or a CNPJ, stored as
            // digits only so the search does not depend on formatting.
            $table->string('document', 14)->unique();

            $table->string('email');

            // A short string instead of a MySQL ENUM: adding a new status becomes a code
            // change, not an ALTER TABLE migration on a large table. The value is constrained
            // by the PHP enum.
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // The report filters billings by customer, and the customers screen
            // lista por nome.
            $table->index('name');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
