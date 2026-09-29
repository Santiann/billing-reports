import { buttonClasses } from "@/components/ui/button";
import type { ReportExportInfo, ReportFilters } from "@/types/report";

type ReportExportProps = {
  filters: ReportFilters;
  info: ReportExportInfo;
  count: number;
};

/**
 * The export links.
 *
 * They are ordinary anchors pointing at Next's own Route Handlers: the browser's
 * navigation triggers the download, and the token is attached on the server. The
 * filters travel in the query string, so the file comes out with the same scope
 * that is on screen.
 *
 * The PDF has a cap and the CSV does not. When the scope exceeds the cap, the
 * button becomes explanatory text pointing at the CSV — warning beforehand beats
 * letting the user click and receive a 422.
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
