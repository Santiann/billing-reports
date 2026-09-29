import { createCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";
import { Card, CardBody } from "@/components/ui/card";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";

export default async function NewCustomerPage() {
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
        title="Novo cliente"
        voltar={{ href: "/clientes", label: "Clientes" }}
      />

      <Card>
        <CardBody className="p-6">
          <CustomerForm
            action={createCustomer}
            submitLabel="Cadastrar"
            cancelHref="/clientes"
          />
        </CardBody>
      </Card>
    </div>
  );
}
