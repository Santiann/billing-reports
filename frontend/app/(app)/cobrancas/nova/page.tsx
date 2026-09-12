import { createBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";
import { Card, CardBody } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";

export default function NewBillingPage() {
  return (
    <div>
      <PageHeader
        title="Nova cobrança"
        voltar={{ href: "/cobrancas", label: "Cobranças" }}
      />

      <Card>
        <CardBody className="p-6">
          <BillingForm
            action={createBilling}
            submitLabel="Cadastrar"
            cancelHref="/cobrancas"
          />
        </CardBody>
      </Card>
    </div>
  );
}
