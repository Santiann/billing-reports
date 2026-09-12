import Link from "next/link";

import { buttonClasses } from "@/components/ui/button";
import { PageHeader } from "@/components/ui/page-header";

/**
 * Tela de operação não permitida pelo perfil.
 *
 * Existe porque esconder o link não impede ninguém de digitar o endereço, e
 * quem digita merece a explicação em vez de um 404 mentiroso — a página existe,
 * o que falta é permissão.
 *
 * O backend recusa a mesma operação de qualquer forma. Isto é cortesia, não
 * segurança.
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
