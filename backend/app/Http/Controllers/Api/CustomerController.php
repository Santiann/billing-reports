<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\IndexCustomerRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    /**
     * Filtro, ordenação e paginação acontecem no banco. Em nenhum ponto o
     * conjunto inteiro é carregado para ser recortado em memória.
     */
    public function index(IndexCustomerRequest $request): AnonymousResourceCollection
    {
        $query = Customer::query();

        if ($search = $request->validated('search')) {
            $query->where(function (Builder $builder) use ($search) {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");

                // Documento só entra na busca se o termo tiver dígitos. Sem
                // essa guarda, um termo puramente textual viraria
                // `document like '%'` e traria a tabela inteira.
                $digits = preg_replace('/\D/', '', $search);

                if ($digits !== '') {
                    $builder->orWhere('document', 'like', "{$digits}%");
                }
            });
        }

        if ($status = $request->validated('status')) {
            $query->where('status', $status);
        }

        $query->orderBy(
            $request->validated('sort') ?? 'name',
            $request->validated('direction') ?? 'asc',
        );

        return CustomerResource::collection(
            $query->paginate($request->validated('per_page') ?? 15)->withQueryString(),
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return CustomerResource::make($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return CustomerResource::make($customer);
    }
}
