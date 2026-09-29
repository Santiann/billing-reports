import { notFound } from "next/navigation";

import { updateCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";
import { Card, CardBody } from "@/components/ui/card";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";
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

  // bind pins the id as the first argument: the action still receives
  // (state, formData) from useActionState.
  const action = updateCustomer.bind(null, customer.id);

  // The screen is not the barrier — the backend refuses the operation either way —
  // but whoever types the address deserves the explanation, not a form that will
  // fail on submit.
  const { can_write: canWrite } = await getSessionUser();

  if (!canWrite) {
    return <Forbidden voltar={{ href: "/clientes", label: "Clientes" }} />;
  }

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
