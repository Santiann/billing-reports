import type { ReportFilters } from "@/types/report";

/**
 * Links de exportação.
 *
 * São âncoras comuns apontando para os Route Handlers do próprio Next: a
 * navegação do browser dispara o download, e o token é anexado no servidor.
 * Os filtros da tela viajam na query string, então o arquivo sai com o mesmo
 * recorte que está sendo exibido.
 */
export function ReportExport({ filters }: { filters: ReportFilters }) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(filters)) {
    if (value !== null && value !== undefined && value !== "") {
      params.set(key, String(value));
    }
  }

  const query = params.toString();

  return (
    <div className="flex items-center gap-2">
      <a
        href={`/api/reports/billings/csv?${query}`}
        className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100"
      >
        Exportar CSV
      </a>
    </div>
  );
}
