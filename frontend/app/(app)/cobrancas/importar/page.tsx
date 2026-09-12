import Link from "next/link";

import { importBillings } from "@/app/actions/imports";
import { ImportForm } from "@/components/imports/import-form";
import { PageHeader } from "@/components/ui/page-header";

const COLUNAS = [
  { field: "document", label: "Documento" },
  { field: "description", label: "Descrição" },
  { field: "original_amount", label: "Valor" },
  { field: "monthly_interest_rate", label: "Taxa" },
  { field: "issue_date", label: "Emissão" },
  { field: "due_date", label: "Vencimento" },
] as const;

const EXEMPLO = `documento;descricao;valor;taxa;emissao;vencimento
12345678000190;Mensalidade de agosto;1.500,00;0,02;10/07/2026;09/08/2026
98765432000155;Consultoria;800,00;0,02;2026-07-15;2026-08-15`;

export default function ImportBillingsPage() {
  return (
    <div>
      <PageHeader
        title="Importar cobranças"
        voltar={{ href: "/cobrancas", label: "Cobranças" }}
      />

      <div className="mb-6 max-w-2xl space-y-2 text-sm text-ink-muted">
        <p>
          O cliente é encontrado pelo <strong className="text-ink">documento</strong>,
          que precisa já estar cadastrado. Linha cujo documento não existe não
          impede as outras de entrar — ela volta nomeada.{" "}
          <Link href="/clientes/importar" className="text-accent hover:underline">
            Importar clientes primeiro
          </Link>
          .
        </p>
        <p>
          Toda cobrança importada nasce{" "}
          <strong className="text-ink">pendente</strong>, como a cadastrada pela
          tela. Coluna de status ou de pagamento no arquivo é ignorada: quem faz
          essa transição é o registro de pagamento, que grava os juros
          congelados junto.
        </p>
      </div>

      <ImportForm
        action={importBillings}
        columns={COLUNAS}
        labelField="description"
        exampleCsv={EXEMPLO}
        voltarHref="/cobrancas"
      />
    </div>
  );
}
