import { fetchAsUser } from "@/lib/server-api";
import type { Dashboard } from "@/types/dashboard";

/**
 * Uma chamada só para a tela inteira.
 *
 * Os dois blocos — indicadores do mês e série de doze meses — vêm juntos
 * porque são duas consultas no banco e uma resposta. Buscar em dois endpoints
 * criaria duas idas ao servidor em sequência para montar a mesma tela.
 */
export function getDashboard(): Promise<Dashboard> {
  return fetchAsUser<Dashboard>("/api/dashboard");
}
