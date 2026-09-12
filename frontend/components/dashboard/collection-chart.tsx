import type { DashboardMonth } from "@/types/dashboard";

/**
 * Taxa de recebimento por mês: quanto do faturado virou dinheiro.
 *
 * Sai dos mesmos doze números do gráfico acima e não custa consulta nenhuma —
 * mas responde outra pergunta. O empilhado mostra volume; este mostra
 * eficiência de cobrança, que é o que não se enxerga quando o faturamento
 * cresce e o recebido cresce junto.
 *
 * Linha, porque a leitura é tendência. Série única, então sem caixa de legenda:
 * o título já diz o que está plotado, e um quadradinho só repetiria o título.
 */

const LARGURA = 760;
const ALTURA = 180;
const MARGEM = { topo: 16, direita: 44, baixo: 28, esquerda: 44 };

export function CollectionChart({ meses }: { meses: DashboardMonth[] }) {
  const pontos = meses.map((mes) => {
    const faturado = Number(mes.original_amount);
    const recebido = Number(mes.received_amount);

    return {
      label: mes.label,
      month: mes.month,
      taxa: faturado > 0 ? (recebido / faturado) * 100 : 0,
    };
  });

  const areaLargura = LARGURA - MARGEM.esquerda - MARGEM.direita;
  const areaAltura = ALTURA - MARGEM.topo - MARGEM.baixo;
  const base = MARGEM.topo + areaAltura;

  // Escala fixa de 0 a 100: taxa de recebimento é porcentagem, e esticar o eixo
  // para o intervalo dos dados transformaria variação de dois pontos numa
  // montanha.
  const x = (i: number) =>
    MARGEM.esquerda + (areaLargura / Math.max(pontos.length - 1, 1)) * i;
  const y = (taxa: number) => base - (taxa / 100) * areaAltura;

  const caminho = pontos
    .map((ponto, i) => `${i === 0 ? "M" : "L"} ${x(i)} ${y(ponto.taxa)}`)
    .join(" ");

  const ultimo = pontos[pontos.length - 1];

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
        className="w-full min-w-[560px]"
        role="img"
        aria-label="Taxa de recebimento por mês nos últimos doze meses"
      >
        {[0, 50, 100].map((marca) => (
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
              {marca}%
            </text>
          </g>
        ))}

        <path
          d={caminho}
          fill="none"
          className="stroke-chart-received"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />

        {pontos.map((ponto, i) => (
          <g key={ponto.month}>
            {/* Anel de 2px na cor da superfície: mantém o ponto legível onde
                ele cruza a linha. */}
            <circle
              cx={x(i)}
              cy={y(ponto.taxa)}
              r="4.5"
              className="fill-chart-received stroke-surface"
              strokeWidth="2"
            />
            <title>{`${ponto.label}: ${ponto.taxa.toFixed(1)}% recebido`}</title>
          </g>
        ))}

        {/* Rótulo direto só na ponta: valor em todo ponto vira ruído. */}
        <text
          x={x(pontos.length - 1) + 10}
          y={y(ultimo.taxa) + 4}
          className="fill-ink text-[11px] font-medium"
        >
          {ultimo.taxa.toFixed(0)}%
        </text>

        {pontos.map((ponto, i) => (
          <text
            key={ponto.month}
            x={x(i)}
            y={ALTURA - 8}
            textAnchor="middle"
            className="fill-ink-faint text-[11px]"
          >
            {ponto.label}
          </text>
        ))}
      </svg>
      </div>
    </figure>
  );
}
