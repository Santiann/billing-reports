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
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function index(IndexBillingRequest $request): AnonymousResourceCollection
    {
        // Eager loading the customer: the listing shows the name, and without this it would
        // be one SELECT per row while serialising.
        $query = Billing::query()->with('customer');

        // The SQL face of the interest calculation. The updated amount comes out of the SELECT
        // itself, and that is what will make it possible to SORT by it and SUM it over the whole
        // filtered set without loading anything into memory.
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

        // Defaults to `id desc`: it is the primary key, so sorting by it costs no filesort. The
        // other sortable columns get their index in the report indexes step.
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
        // In a transaction: the data version goes up along with the INSERT, and the totals cache
        // never sees the new billing without the new version.
        $billing = DB::transaction(fn () => Billing::create($request->validated()));

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
        // `updateOrFail` opens a transaction: the trail is written inside it, and without the
        // trail the edit does not stick.
        $billing->updateOrFail($request->validated());

        return BillingResource::make($billing->load('customer'));
    }

    /**
     * Reverses the payment: pending again, interest from the original due date.
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
     * The audit trail, newest change first.
     */
    public function audit(Billing $billing): AnonymousResourceCollection
    {
        return BillingAuditResource::collection(
            $billing->audits()->with('user')->latest('id')->paginate(50),
        );
    }

    /**
     * Records the payment and freezes the interest at the given date.
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
