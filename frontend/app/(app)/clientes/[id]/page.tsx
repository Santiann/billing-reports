import Link from "next/link";
import { notFound } from "next/navigation";

import { Badge } from "@/components/ui/badge";
import { buttonClasses } from "@/components/ui/button";
import { Definitions } from "@/components/ui/definitions";
import { Feedback } from "@/components/ui/feedback";
import { PageHeader } from "@/components/ui/page-header";
import { ApiError } from "@/lib/api";
import { getCustomer } from "@/lib/customers";
import type { Customer } from "@/types/customer";

type PageProps = {
  params: Promise<{ id: string }>;
  searchParams: Promise<Record<string, string | undefined>>;
};

export default async function CustomerPage({ params, searchParams }: PageProps) {
  const { id } = await params;
  const query = await searchParams;

  let customer: Customer;

  try {
    customer = await getCustomer(id);
  } catch (error) {
    // 404 da API vira 404 do Next, não erro genérico.
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }

  return (
    <div>
      {/* Nome e status sobem da ficha para o cabeçalho: repetir o nome logo
          abaixo do título era a única linha que a ficha tinha de sobra. */}
      <PageHeader
        title={customer.name}
        voltar={{ href: "/clientes", label: "Clientes" }}
        badge={
          <Badge tone={customer.status === "active" ? "positive" : "neutral"}>
            {customer.status_label}
          </Badge>
        }
        action={
          <Link
            href={`/clientes/${customer.id}/editar`}
            className={buttonClasses({ variant: "secondary" })}
          >
            Editar
          </Link>
        }
      />

      <Feedback code={query.sucesso} />

      <Definitions
        items={[
          { label: "Documento", value: customer.document, mono: true },
          { label: "E-mail", value: customer.email },
        ]}
      />

      <p className="mt-6">
        <Link
          href={`/cobrancas?customer_id=${customer.id}`}
          className="text-sm text-accent hover:underline"
        >
          Ver as cobranças deste cliente →
        </Link>
      </p>
    </div>
  );
}
