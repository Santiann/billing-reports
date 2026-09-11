import Link from "next/link";
import { notFound, redirect } from "next/navigation";

import { updateBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";
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
      <nav className="mb-2 text-sm">
        <Link
          href={`/cobrancas/${billing.id}`}
          className="text-slate-600 hover:text-slate-900"
        >
          ← {billing.description}
        </Link>
      </nav>

      <h1 className="mb-6 text-xl font-semibold text-slate-900">
        Editar cobrança
      </h1>

      <div className="rounded-lg border border-slate-200 bg-white p-6">
        <BillingForm
          action={action}
          billing={billing}
          submitLabel="Salvar alterações"
          cancelHref={`/cobrancas/${billing.id}`}
        />
      </div>
    </div>
  );
}
