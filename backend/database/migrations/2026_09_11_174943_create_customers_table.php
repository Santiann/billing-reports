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

            // Documento é a identidade de negócio do cliente: CPF ou CNPJ,
            // guardado só com dígitos para a busca não depender de máscara.
            $table->string('document', 14)->unique();

            $table->string('email');

            // String curta em vez de ENUM do MySQL: acrescentar um status novo
            // vira mudança de código, não migration de ALTER TABLE em tabela
            // grande. O valor é restringido pelo enum de PHP.
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // O relatório filtra cobranças por cliente, e a tela de clientes
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
