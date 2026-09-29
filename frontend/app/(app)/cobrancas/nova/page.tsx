import { createBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";
import { Card, CardBody } from "@/components/ui/card";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";

export default async function NewBillingPage() {
  // The screen is not the barrier — the backend refuses the operation either way —
  // but whoever types the address deserves the explanation, not a form that will
  // fail on submit.
  const { can_write: canWrite } = await getSessionUser();

  if (!canWrite) {
    return <Forbidden voltar={{ href: "/cobrancas", label: "Cobranças" }} />;
  }

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
