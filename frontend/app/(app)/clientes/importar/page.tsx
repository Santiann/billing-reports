import { importCustomers } from "@/app/actions/imports";
import { ImportForm } from "@/components/imports/import-form";
import { Forbidden } from "@/components/ui/forbidden";
import { PageHeader } from "@/components/ui/page-header";
import { getSessionUser } from "@/lib/session-user";

const COLUMNS = [
  { field: "name", label: "Nome" },
  { field: "document", label: "Documento" },
  { field: "email", label: "E-mail" },
  { field: "status", label: "Status" },
] as const;

const EXAMPLE_CSV = `nome;documento;email;status
Comércio Silva LTDA;12345678000190;financeiro@silva.test;ativo
Padaria do Bairro ME;98765432000155;contato@padaria.test;ativo`;

export default async function ImportCustomersPage() {
  // The screen is not the barrier — the backend refuses the operation either way —
  // but whoever types the address deserves the explanation, not a form that will
  // fail on submit.
  const { can_write: canWrite } = await getSessionUser();

  if (!canWrite) {
    return <Forbidden voltar={{ href: "/clientes", label: "Clientes" }} />;
  }

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
        columns={COLUMNS}
        labelField="name"
        exampleCsv={EXAMPLE_CSV}
        backHref="/clientes"
      />
    </div>
  );
}
