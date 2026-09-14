<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\InterestCalculator;
use App\Domain\Billing\RegisterPayment;
use App\Domain\Billing\ReversePayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\IndexBillingRequest;
use App\Http\Requests\Billing\RegisterPaymentRequest;
use App\Http\Requests\Billing\ReversePaymentRequest;
use App\Http\Requests\Billing\StoreBillingRequest;
use App\Http\Requests\Billing\UpdateBillingRequest;
use App\Http\Resources\BillingAuditResource;
use App\Http\Resources\BillingResource;
use App\Models\Billing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingController extends Controller
{
    public function index(IndexBillingRequest $request): AnonymousResourceCollection
    {
        // Eager loading do cliente: a listagem exibe o nome, e sem isto seria
        // um SELECT por linha ao serializar.
        $query = Billing::query()->with('customer');

        // Face SQL do cálculo de juros. O valor atualizado sai do próprio
        // SELECT, e é isso que vai permitir ORDENAR por ele e SOMÁ-LO sobre o
        // conjunto filtrado inteiro sem carregar nada em memória.
        $calculator = new InterestCalculator();

        $query->select('billings.*')
            ->selectRaw("{$calculator->updatedAmountSql()} as updated_amount")
            ->selectRaw("{$calculator->interestAmountSql()} as interest_amount");

        if ($customerId = $request->validated('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->validated('search')) {
            $query->where('description', 'like', "%{$search}%");
        }

        // Default por `id desc`: é a chave primária, então ordenar por ela não
        // custa filesort. As outras colunas de ordenação ganham índice na
        // etapa de índices do relatório.
        $query->orderBy(
            $request->validated('sort') ?? 'id',
            $request->validated('direction') ?? 'desc',
        );

        return BillingResource::collection(
            $query->paginate($request->validated('per_page') ?? 15)->withQueryString(),
        );
    }

    public function store(StoreBillingRequest $request): JsonResponse
    {
        $billing = Billing::create($request->validated());

        return BillingResource::make($billing->load('customer'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Billing $billing): BillingResource
    {
        return BillingResource::make($billing->load('customer'));
    }

    public function update(UpdateBillingRequest $request, Billing $billing): BillingResource
    {
        // `updateOrFail` abre transação: a trilha é gravada dentro dela, e sem
        // trilha a edição não fica.
        $billing->updateOrFail($request->validated());

        return BillingResource::make($billing->load('customer'));
    }

    /**
     * Estorna o pagamento: pendente de novo, juros desde o vencimento original.
     */
    public function reverse(
        ReversePaymentRequest $request,
        Billing $billing,
        ReversePayment $reversePayment,
    ): BillingResource {
        $reversePayment($billing);

        return BillingResource::make($billing->fresh()->load('customer'));
    }

    /**
     * A trilha de auditoria, da alteração mais recente para a mais antiga.
     */
    public function audit(Billing $billing): AnonymousResourceCollection
    {
        return BillingAuditResource::collection(
            $billing->audits()->with('user')->latest('id')->paginate(50),
        );
    }

    /**
     * Registra o pagamento e congela os juros na data informada.
     */
    public function pay(
        RegisterPaymentRequest $request,
        Billing $billing,
        RegisterPayment $registerPayment,
    ): BillingResource {
        $registerPayment(
            $billing,
            $request->validated('payment_date'),
            $request->validated('paid_amount'),
        );

        return BillingResource::make($billing->fresh()->load('customer'));
    }
}
