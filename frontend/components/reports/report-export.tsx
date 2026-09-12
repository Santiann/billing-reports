import { buttonClasses } from "@/components/ui/button";
import type { ReportExportInfo, ReportFilters } from "@/types/report";

type ReportExportProps = {
  filters: ReportFilters;
  info: ReportExportInfo;
  count: number;
};

/**
 * Links de exportação.
 *
 * São âncoras comuns apontando para os Route Handlers do próprio Next: a
 * navegação do browser dispara o download, e o token é anexado no servidor.
 * Os filtros viajam na query string, então o arquivo sai com o mesmo recorte
 * que está na tela.
 *
 * O PDF tem teto e o CSV não. Quando o recorte passa do teto, o botão vira
 * texto explicativo apontando o CSV — avisar antes é melhor do que deixar o
 * usuário clicar e receber um 422.
 */
export function ReportExport({ filters, info, count }: ReportExportProps) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== null && value !== undefined && value !== "") {
      params.set(key, String(value));
    }
  }

  const query = params.toString();

  const buttonClass = buttonClasses({ variant: "secondary" });

  return (
    <div className="flex flex-wrap items-center justify-end gap-2">
      {info.pdf_available ? (
        <a href={`/api/reports/billings/pdf?${query}`} className={buttonClass}>
          Exportar PDF
        </a>
      ) : (
        <span
          className="max-w-sm rounded-md border border-pending/30 bg-pending-soft px-3 py-2 text-xs text-pending"
          role="status"
        >
          PDF indisponível: {count.toLocaleString("pt-BR")} cobranças acima do
          teto de {info.pdf_max_rows.toLocaleString("pt-BR")}. Use o CSV, que
          não tem limite.
        </span>
      )}

      <a href={`/api/reports/billings/csv?${query}`} className={buttonClass}>
        Exportar CSV
      </a>
    </div>
  );
}
