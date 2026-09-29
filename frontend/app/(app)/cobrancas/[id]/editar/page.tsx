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

  // The API refuses to edit a paid billing; barring it here avoids serving a form
  // that would only fail on submit.
  if (billing.status === "paid") {
    redirect(`/cobrancas/${billing.id}`);
  }

  const action = updateBilling.bind(null, billing.id);

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
