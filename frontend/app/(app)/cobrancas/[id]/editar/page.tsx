import { notFound, redirect } from "next/navigation";

import { updateBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";
import { Card, CardBody } from "@/components/ui/card";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";
import { ApiError } from "@/lib/api";
import { getBilling } from "@/lib/billings";
import type { Billing } from "@/types/billing";

type PageProps = {
  params: Promise<{ id: string }>;
};

export default async function EditBillingPage({ params }: PageProps) {
  const { id } = await params;

  let billing: Billing;

  try {
    billing = await getBilling(id);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }

  // A API recusa editar cobrança paga; barrar aqui evita servir um formulário
  // que só falharia no submit.
  if (billing.status === "paid") {
    redirect(`/cobrancas/${billing.id}`);
  }

  const action = updateBilling.bind(null, billing.id);

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
        title="Editar cobrança"
        voltar={{ href: `/cobrancas/${billing.id}`, label: billing.description }}
      />

      <Card>
        <CardBody className="p-6">
          <BillingForm
            action={action}
            billing={billing}
            submitLabel="Salvar alterações"
            cancelHref={`/cobrancas/${billing.id}`}
          />
        </CardBody>
      </Card>
    </div>
  );
}
