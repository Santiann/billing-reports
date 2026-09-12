/**
 * A figura da dobra: o que uma cobrança de mil reais vira quando atrasa.
 *
 * Mostra o RESULTADO do sistema, não a interface dele. Uma captura de tela
 * seria menos legível e diria menos — quem chega nesta página quer entender o
 * que o sistema calcula, não como ele é por dentro.
 *
 * Os números são reais: 1.000,00 a 2% ao mês pela fórmula de juros compostos
 * que o sistema usa, `valor * (1 + taxa) ^ (dias / 30)`. Em 90 dias dá
 * 1.061,21. Inventar a curva aqui seria mentir sobre a única coisa que esta
 * página tem para provar.
 */

const PONTOS = [
  { dias: 0, valor: 1000.0 },
  { dias: 15, valor: 1009.95 },
  { dias: 30, valor: 1020.0 },
  { dias: 45, valor: 1030.15 },
  { dias: 60, valor: 1040.4 },
  { dias: 75, valor: 1050.75 },
  { dias: 90, valor: 1061.21 },
];

const LARGURA = 420;
const ALTURA = 300;
const MARGEM = { topo: 46, direita: 16, baixo: 40, esquerda: 16 };

export function InterestCurve() {
  const areaLargura = LARGURA - MARGEM.esquerda - MARGEM.direita;
  const areaAltura = ALTURA - MARGEM.topo - MARGEM.baixo;
  const base = MARGEM.topo + areaAltura;

  const minimo = 995;
  const maximo = 1065;

  const x = (dias: number) => MARGEM.esquerda + (dias / 90) * areaLargura;
  const y = (valor: number) =>
    base - ((valor - minimo) / (maximo - minimo)) * areaAltura;

  const linha = PONTOS.map(
    (ponto, i) => `${i === 0 ? "M" : "L"} ${x(ponto.dias)} ${y(ponto.valor)}`,
  ).join(" ");

  const area = `${linha} L ${x(90)} ${base} L ${x(0)} ${base} Z`;
  const ultimo = PONTOS[PONTOS.length - 1];

  return (
    <figure className="m-0 rounded-lg border border-rule bg-surface p-5 shadow-card">
      <figcaption className="mb-1 text-xs uppercase tracking-widest text-ink-faint">
        Uma cobrança de R$ 1.000,00 a 2% ao mês
      </figcaption>

      <svg
        viewBox={`0 0 ${LARGURA} ${ALTURA}`}
        className="w-full"
        role="img"
        aria-label="Uma cobrança de mil reais a dois por cento ao mês vale mil e sessenta e um reais e vinte e um centavos após noventa dias de atraso"
      >
        {/* Valor original, para a diferença ter contra o que ser lida. */}
        <line
          x1={MARGEM.esquerda}
          x2={LARGURA - MARGEM.direita}
          y1={y(1000)}
          y2={y(1000)}
          className="stroke-rule-strong"
          strokeWidth="1"
        />
        <text
          x={MARGEM.esquerda}
          y={y(1000) + 16}
          className="fill-ink-faint text-[11px]"
        >
          R$ 1.000,00 no vencimento
        </text>

        <path d={area} className="fill-chart-received" opacity="0.1" />
        <path
          d={linha}
          fill="none"
          className="stroke-chart-received"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />

        <circle
          cx={x(ultimo.dias)}
          cy={y(ultimo.valor)}
          r="5"
          className="fill-chart-received stroke-surface"
          strokeWidth="2"
        />

        {/* Rótulo só na ponta: é o número que a página está afirmando. */}
        <text
          x={x(ultimo.dias)}
          y={y(ultimo.valor) - 16}
          textAnchor="end"
          className="fill-ink text-[15px] font-semibold"
        >
          R$ 1.061,21
        </text>

        {[0, 30, 60, 90].map((dias) => (
          <text
            key={dias}
            x={x(dias)}
            y={ALTURA - 12}
            textAnchor={dias === 0 ? "start" : dias === 90 ? "end" : "middle"}
            className="fill-ink-faint text-[11px]"
          >
            {dias === 0 ? "vencimento" : `${dias} dias`}
          </text>
        ))}
      </svg>
    </figure>
  );
}
