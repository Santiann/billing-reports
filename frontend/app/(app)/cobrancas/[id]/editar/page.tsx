import { notFound, redirect } from "next/navigation";

import { updateBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";
import { Card, CardBody } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
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
