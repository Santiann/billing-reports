import { createCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";
import { Card, CardBody } from "@/components/ui/card";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";

export default async function NewCustomerPage() {
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
