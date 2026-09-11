<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: cobrança é registro financeiro. Apagar um
            // cliente não pode evaporar o histórico de faturamento dele.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            $table->string('description');

            // DECIMAL, nunca FLOAT. Dinheiro em ponto flutuante acumula erro
            // de centavo, e o relatório soma milhões de linhas.
            $table->decimal('original_amount', 12, 2);

            // Taxa mensal como fração: 0.0200 = 2% ao mês.
            $table->decimal('monthly_interest_rate', 6, 4)->default(0);

            // As três datas que o usuário pode escolher como base do período.
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('payment_date')->nullable();

            // Apenas 'pending' e 'paid'.
            //
            // "Vencida" NÃO é status armazenado: é derivável em SQL com
            // status = 'pending' AND due_date < CURDATE(). Guardá-la exigiria
            // um job diário virando linhas de pendente para vencida, e entre
            // duas execuções a coluna estaria mentindo. Derivar é sempre
            // correto e não custa escrita.
            $table->string('status', 20)->default('pending');

            // Congelamento no ato do pagamento.
            //
            // Cobrança paga não acumula juros: o valor exibido vem daqui e
            // nunca de recálculo. Sem estas colunas, uma cobrança paga com
            // atraso mudaria de valor a cada dia que passasse.
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->decimal('paid_interest_amount', 12, 2)->nullable();

            $table->timestamps();

            // Índices compostos do relatório entram na etapa própria
            // (feat: add report indexes), junto da query que cada um serve.
            // Aqui fica só o que a integridade do schema já exige.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};
