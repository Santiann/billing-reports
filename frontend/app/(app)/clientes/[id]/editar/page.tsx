import { notFound } from "next/navigation";

import { updateCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";
import { Card, CardBody } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
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
      <PageHeader
        title="Editar cliente"
        voltar={{ href: `/clientes/${customer.id}`, label: customer.name }}
      />

      <Card>
        <CardBody className="p-6">
          <CustomerForm
            action={action}
            customer={customer}
            submitLabel="Salvar alterações"
            cancelHref={`/clientes/${customer.id}`}
          />
        </CardBody>
      </Card>
    </div>
  );
}
