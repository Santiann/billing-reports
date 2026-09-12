import Link from "next/link";

import { CollectionChart } from "@/components/dashboard/collection-chart";
import { MonthlyChart } from "@/components/dashboard/monthly-chart";
import { PeriodSummary } from "@/components/dashboard/period-summary";
import { buttonClasses } from "@/components/ui/button";
import { Card, CardBody, CardHeader } from "@/components/ui/card";
import { PageHeader } from "@/components/ui/page-header";
import { Table, TBody, TD, TH, THead, TR } from "@/components/ui/table";
import { getDashboard } from "@/lib/dashboard";
import { formatCurrency } from "@/lib/format";

/**
 * Tela inicial.
 *
 * Uma chamada, duas consultas de agregação, nenhuma linha carregada para o PHP
 * somar. Medido contra 2.000.000 de cobranças: 1,35s a 1,60s de ponta a ponta.
 */
export default async function DashboardPage() {
  const { period, monthly } = await getDashboard();

  return (
    <div>
      <PageHeader
        title="Visão geral"
        action={
          <Link href="/relatorio" className={buttonClasses()}>
            Abrir relatório
          </Link>
        }
      />

      <PeriodSummary period={period} />

      <Card className="mb-6">
        <CardHeader title="Faturado e recebido, por mês de vencimento" />
        <CardBody>
          <MonthlyChart meses={monthly} />
        </CardBody>
      </Card>

      <Card className="mb-6">
        <CardHeader title="Taxa de recebimento" />
        <CardBody>
          <CollectionChart meses={monthly} />
        </CardBody>
      </Card>

      {/*
        Os mesmos números em tabela.
        Fechada por padrão para não competir com os gráficos, e presente porque
        gráfico não é a única forma de ler: quem usa leitor de tela, quem precisa
        do valor exato e quem vai copiar para outro lugar precisam da tabela.
      */}
      <details className="rounded-lg border border-rule bg-surface">
        <summary className="cursor-pointer px-4 py-3 text-sm font-medium text-ink">
          Ver os números em tabela
        </summary>

        <Table label="Faturado e recebido por mês">
          <THead>
            <TH>Mês</TH>
            <TH numeric>Cobranças</TH>
            <TH numeric>Faturado</TH>
            <TH numeric>Recebido</TH>
            <TH numeric>Taxa</TH>
          </THead>
          <TBody>
            {monthly.map((mes) => {
              const faturado = Number(mes.original_amount);
              const taxa =
                faturado > 0 ? (Number(mes.received_amount) / faturado) * 100 : 0;

              return (
                <TR key={mes.month}>
                  <TD>{mes.label}</TD>
                  <TD numeric>{mes.count.toLocaleString("pt-BR")}</TD>
                  <TD numeric>{formatCurrency(mes.original_amount)}</TD>
                  <TD numeric>{formatCurrency(mes.received_amount)}</TD>
                  <TD numeric>{taxa.toFixed(1)}%</TD>
                </TR>
              );
            })}
          </TBody>
        </Table>
      </details>
    </div>
  );
}
