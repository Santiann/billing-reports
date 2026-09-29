import Link from "next/link";

import { buttonClasses } from "@/components/ui/button";
import { PageHeader } from "@/components/ui/page-header";

/**
 * The screen for an operation the role does not allow.
 *
 * It exists because hiding the link stops nobody from typing the address, and
 * whoever types it deserves the explanation rather than a lying 404 — the page
 * exists, what is missing is permission.
 *
 * The backend refuses the same operation either way. This is courtesy, not
 * security.
 */
export function Forbidden({
  voltar,
}: {
  voltar: { href: string; label: string };
}) {
  return (
    <div>
      <PageHeader title="Sem permissão" voltar={voltar} />

      <p className="max-w-xl text-ink-muted">
        Seu perfil é de <strong className="text-ink">consulta</strong>: você vê
        todos os clientes, cobranças e relatórios, mas não cadastra, edita nem
        registra pagamento.
      </p>

      <Link
        href={voltar.href}
        className={`${buttonClasses({ variant: "secondary" })} mt-6`}
      >
        Voltar para {voltar.label.toLowerCase()}
      </Link>
    </div>
  );
}
