import Link from "next/link";

import { createCustomer } from "@/app/actions/customers";
import { CustomerForm } from "@/components/customers/customer-form";

export default function NewCustomerPage() {
  return (
    <div>
      <nav className="mb-2 text-sm">
        <Link href="/clientes" className="text-slate-600 hover:text-slate-900">
          ← Clientes
        </Link>
      </nav>

      <h1 className="mb-6 text-xl font-semibold text-slate-900">Novo cliente</h1>

      <div className="rounded-lg border border-slate-200 bg-white p-6">
        <CustomerForm
          action={createCustomer}
          submitLabel="Cadastrar"
          cancelHref="/clientes"
        />
      </div>
    </div>
  );
}
