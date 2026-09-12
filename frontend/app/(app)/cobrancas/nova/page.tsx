import { createBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";
import { Card, CardBody } from "@/components/ui/card";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";

export default async function NewBillingPage() {
  // A tela não é a barreira — o backend recusa a operação de qualquer forma —
  // mas quem digita o endereço merece a explicação, não um formulário que vai
  // falhar no envio.
  const { can_write: podeEscrever } = await getSessionUser();

  if (!podeEscrever) {
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
