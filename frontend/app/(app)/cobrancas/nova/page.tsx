import Link from "next/link";

import { createBilling } from "@/app/actions/billings";
import { BillingForm } from "@/components/billings/billing-form";

export default function NewBillingPage() {
  return (
    <div>
      <nav className="mb-2 text-sm">
        <Link href="/cobrancas" className="text-slate-600 hover:text-slate-900">
          ← Cobranças
        </Link>
      </nav>

      <h1 className="mb-6 text-xl font-semibold text-slate-900">Nova cobrança</h1>

      <div className="rounded-lg border border-slate-200 bg-white p-6">
        <BillingForm
          action={createBilling}
          submitLabel="Cadastrar"
          cancelHref="/cobrancas"
        />
      </div>
    </div>
  );
}
