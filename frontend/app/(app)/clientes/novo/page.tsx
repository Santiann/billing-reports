import { createCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";
import { Card, CardBody } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";

export default function NewCustomerPage() {
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
