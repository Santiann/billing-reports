import { importCustomers } from "@/app/actions/imports";
import { ImportForm } from "@/components/imports/import-form";
import { PageHeader } from "@/components/ui/page-header";

const COLUNAS = [
  { field: "name", label: "Nome" },
  { field: "document", label: "Documento" },
  { field: "email", label: "E-mail" },
  { field: "status", label: "Status" },
] as const;

const EXEMPLO = `nome;documento;email;status
Comércio Silva LTDA;12345678000190;financeiro@silva.test;ativo
Padaria do Bairro ME;98765432000155;contato@padaria.test;ativo`;

export default function ImportCustomersPage() {
  return (
    <div>
      <PageHeader
        title="Importar clientes"
        voltar={{ href: "/clientes", label: "Clientes" }}
      />

      <p className="mb-6 max-w-2xl text-sm text-ink-muted">
        O arquivo é analisado antes de qualquer coisa ser gravada. Linha com erro
        não impede as outras de entrar: as válidas são importadas e as recusadas
        voltam com a linha e o motivo.
      </p>

      <ImportForm
        action={importCustomers}
        columns={COLUNAS}
        exampleCsv={EXEMPLO}
        voltarHref="/clientes"
      />
    </div>
  );
}
