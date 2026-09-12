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

  // bind fixa o id no primeiro argumento: a action continua recebendo
  // (state, formData) do useActionState.
  const action = updateCustomer.bind(null, customer.id);

  // A tela não é a barreira — o backend recusa a operação de qualquer forma —
  // mas quem digita o endereço merece a explicação, não um formulário que vai
  // falhar no envio.
  const { can_write: podeEscrever } = await getSessionUser();

  if (!podeEscrever) {
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
