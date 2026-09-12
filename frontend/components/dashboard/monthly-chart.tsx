import { formatCurrency } from "@/lib/format";
import type { DashboardMonth } from "@/types/dashboard";

/**
 * Faturado por mês, dividido entre o que entrou e o que falta entrar.
 *
 * Coluna empilhada porque a pergunta é parte-todo ao longo do tempo: a altura
 * inteira é o faturado do mês, e o corte mostra quanto virou dinheiro. Duas
 * barras lado a lado responderiam "qual é maior", que não é a pergunta.
 *
 * SVG montado no servidor, sem JavaScript. O gráfico não tem estado — é uma
 * figura de doze números — e a interação que ele precisa (destacar a coluna sob
 * o cursor e mostrar o valor) o CSS resolve sozinho.
 */

const LARGURA = 760;
const ALTURA = 260;
const MARGEM = { topo: 16, direita: 8, baixo: 28, esquerda: 68 };

/** Teto arredondado para cima, para o eixo ter número limpo. */
function tetoRedondo(valor: number): number {
  if (valor <= 0) {
    return 1;
  }

  const ordem = 10 ** Math.floor(Math.log10(valor));
  return Math.ceil(valor / (ordem / 2)) * (ordem / 2);
}

function compacto(valor: number): string {
  if (valor >= 1_000_000) {
    return `${(valor / 1_000_000).toLocaleString("pt-BR", { maximumFractionDigits: 1 })} mi`;
  }

  if (valor >= 1_000) {
    return `${Math.round(valor / 1_000).toLocaleString("pt-BR")} mil`;
  }

  return valor.toLocaleString("pt-BR");
}

export function MonthlyChart({ meses }: { meses: DashboardMonth[] }) {
  const dados = meses.map((mes) => ({
    ...mes,
    faturado: Number(mes.original_amount),
    recebido: Number(mes.received_amount),
  }));

  const teto = tetoRedondo(Math.max(...dados.map((d) => d.faturado)));
  const areaLargura = LARGURA - MARGEM.esquerda - MARGEM.direita;
  const areaAltura = ALTURA - MARGEM.topo - MARGEM.baixo;
  const base = MARGEM.topo + areaAltura;

  const banda = areaLargura / dados.length;
  // Teto de 24px na coluna: o que sobra da banda é ar, de propósito.
  const largura = Math.min(24, banda * 0.5);

  const y = (valor: number) => base - (valor / teto) * areaAltura;
  const marcas = [0, 0.25, 0.5, 0.75, 1].map((f) => teto * f);

  return (
    <figure className="m-0">
      {/* Rola na horizontal em tela estreita, como as tabelas.
          O SVG escala o desenho inteiro, rótulo incluído: em 360px o texto de
          11px viraria 5px e o eixo ficaria ilegível. Melhor rolar o gráfico e
          manter o rótulo do tamanho que se lê — e quem não quiser rolar tem a
          tabela logo abaixo. */}
      <div className="overflow-x-auto">
      <svg
        viewBox={`0 0 ${LARGURA} ${ALTURA}`}
        className="w-full min-w-[640px]"
        role="img"
        aria-label="Faturado e recebido por mês nos últimos doze meses"
      >
        {/* Grade: fio de cabelo, sólido, recuado. Nunca tracejado. */}
        {marcas.map((marca) => (
          <g key={marca}>
            <line
              x1={MARGEM.esquerda}
              x2={LARGURA - MARGEM.direita}
              y1={y(marca)}
              y2={y(marca)}
              className="stroke-rule"
              strokeWidth="1"
            />
            <text
              x={MARGEM.esquerda - 10}
              y={y(marca) + 4}
              textAnchor="end"
              className="fill-ink-faint text-[11px]"
            >
              {compacto(marca)}
            </text>
          </g>
        ))}

        {dados.map((mes, i) => {
          const centro = MARGEM.esquerda + banda * i + banda / 2;
          const x = centro - largura / 2;

          const alturaRecebido = Math.max(base - y(mes.recebido), 0);
          const topoPilha = y(mes.faturado);
          // 2px de respiro NA COR DO FUNDO separam os dois segmentos. É o vão
          // que separa, não um contorno — contorno acrescenta tinta que não é
          // dado.
          const alturaAReceber = Math.max(y(mes.recebido) - topoPilha - 2, 0);

          return (
            <g key={mes.month} className="group">
              {/* Alvo de hover da banda inteira, invisível: a coluna sozinha é
                  estreita demais para ser mirada com o mouse. */}
              <rect
                x={MARGEM.esquerda + banda * i}
                y={MARGEM.topo}
                width={banda}
                height={areaAltura}
                fill="transparent"
              />

              {/* A receber, no topo. Ponta arredondada em 4px só aqui: é o fim
                  do dado, e a base fica reta. */}
              <path
                d={arredondadoNoTopo(x, topoPilha, largura, alturaAReceber, 4)}
                className="fill-chart-pending transition-opacity group-hover:opacity-80"
              />

              {/* Recebido, ancorado na linha de base. */}
              <rect
                x={x}
                y={y(mes.recebido)}
                width={largura}
                height={alturaRecebido}
                className="fill-chart-received transition-opacity group-hover:opacity-80"
              />

              <text
                x={centro}
                y={ALTURA - 8}
                textAnchor="middle"
                className="fill-ink-faint text-[11px]"
              >
                {mes.label}
              </text>

              {/* Valor do mês sob o cursor. Rótulo em todo mês seria ilegível;
                  este aparece um de cada vez. */}
              <text
                x={centro}
                y={topoPilha - 8}
                textAnchor="middle"
                className="fill-ink text-[11px] font-medium opacity-0 transition-opacity group-hover:opacity-100"
              >
                {compacto(mes.faturado)}
              </text>

              <title>
                {`${mes.label}: ${formatCurrency(mes.original_amount)} faturado, ${formatCurrency(mes.received_amount)} recebido`}
              </title>
            </g>
          );
        })}

        <line
          x1={MARGEM.esquerda}
          x2={LARGURA - MARGEM.direita}
          y1={base}
          y2={base}
          className="stroke-rule-strong"
          strokeWidth="1"
        />
      </svg>
      </div>

      {/* Legenda: obrigatória com duas séries. A cor mora no quadrado ao lado
          do texto, nunca no texto — verde e âmbar não se leem como tinta. */}
      <figcaption className="mt-3 flex flex-wrap items-center gap-4 text-xs text-ink-muted">
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-xs bg-chart-received" />
          Recebido
        </span>
        <span className="flex items-center gap-1.5">
          <span className="inline-block h-2.5 w-2.5 rounded-xs bg-chart-pending" />
          A receber
        </span>
      </figcaption>
    </figure>
  );
}

/**
 * Retângulo com os dois cantos de cima arredondados.
 *
 * O SVG não tem raio por canto, e `rx` no `<rect>` arredondaria também a base —
 * onde o segmento encosta no de baixo. Altura zero vira caminho vazio.
 */
function arredondadoNoTopo(
  x: number,
  y: number,
  largura: number,
  altura: number,
  raio: number,
): string {
  if (altura <= 0) {
    return "";
  }

  const r = Math.min(raio, altura, largura / 2);

  return [
    `M ${x} ${y + altura}`,
    `L ${x} ${y + r}`,
    `Q ${x} ${y} ${x + r} ${y}`,
    `L ${x + largura - r} ${y}`,
    `Q ${x + largura} ${y} ${x + largura} ${y + r}`,
    `L ${x + largura} ${y + altura}`,
    "Z",
  ].join(" ");
}
