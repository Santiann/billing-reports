import Link from "next/link";
import { notFound } from "next/navigation";

import { updateCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";
import { ApiError } from "@/lib/api";
import { getCustomer } from "@/lib/customers";
import type { Customer } from "@/types/customer";

type PageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditCustomerPage({ params }: PageProps) {
  const { id } = await params;

  let customer: Customer;

  try {
    customer = await getCustomer(id);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }

  // bind fixa o id no primeiro argumento: a action continua recebendo
  // (state, formData) do useActionState.
  const action = updateCustomer.bind(null, customer.id);

  return (
    <div>
      <nav className="mb-2 text-sm">
        <Link
          href={`/clientes/${customer.id}`}
          className="text-slate-600 hover:text-slate-900"
        >
          ← {customer.name}
        </Link>
      </nav>

      <h1 className="mb-6 text-xl font-semibold text-slate-900">
        Editar cliente
      </h1>

      <div className="rounded-lg border border-slate-200 bg-white p-6">
        <CustomerForm
          action={action}
          customer={customer}
          submitLabel="Salvar alterações"
          cancelHref={`/clientes/${customer.id}`}
        />
      </div>
    </div>
  );
}
