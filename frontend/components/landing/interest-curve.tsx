/**
 * The figure above the fold: what a one-thousand-real billing becomes when it
 * goes late.
 *
 * It shows the system's RESULT, not its interface. A screenshot would be less
 * legible and would say less — whoever lands on this page wants to understand
 * what the system computes, not what it looks like inside.
 *
 * The numbers are real: 1,000.00 at 2% a month by the compound interest formula
 * the system uses, `amount * (1 + rate) ^ (days / 30)`. At 90 days that gives
 * 1,061.21. Inventing the curve here would be lying about the one thing this
 * page has to prove.
 */

const POINTS = [
  { days: 0, amount: 1000.0 },
  { days: 15, amount: 1009.95 },
  { days: 30, amount: 1020.0 },
  { days: 45, amount: 1030.15 },
  { days: 60, amount: 1040.4 },
  { days: 75, amount: 1050.75 },
  { days: 90, amount: 1061.21 },
];

const WIDTH = 420;
const HEIGHT = 300;
const MARGIN = { top: 46, right: 16, bottom: 40, left: 16 };

export function InterestCurve() {
  const areaWidth = WIDTH - MARGIN.left - MARGIN.right;
  const areaHeight = HEIGHT - MARGIN.top - MARGIN.bottom;
  const base = MARGIN.top + areaHeight;

  const min = 995;
  const max = 1065;

  const x = (days: number) => MARGIN.left + (days / 90) * areaWidth;
  const y = (amount: number) =>
    base - ((amount - min) / (max - min)) * areaHeight;

  const line = POINTS.map(
    (point, i) => `${i === 0 ? "M" : "L"} ${x(point.days)} ${y(point.amount)}`,
  ).join(" ");

  const area = `${line} L ${x(90)} ${base} L ${x(0)} ${base} Z`;
  const last = POINTS[POINTS.length - 1];

  return (
    <figure className="m-0 rounded-lg border border-rule bg-surface p-5 shadow-card">
      <figcaption className="mb-1 text-xs uppercase tracking-widest text-ink-faint">
        Uma cobrança de R$ 1.000,00 a 2% ao mês
      </figcaption>

      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        className="w-full"
        role="img"
        aria-label="Uma cobrança de mil reais a dois por cento ao mês vale mil e sessenta e um reais e vinte e um centavos após noventa dias de atraso"
      >
        {/* The original amount, so the difference has something to be read against. */}
        <line
          x1={MARGIN.left}
          x2={WIDTH - MARGIN.right}
          y1={y(1000)}
          y2={y(1000)}
          className="stroke-rule-strong"
          strokeWidth="1"
        />
        <text
          x={MARGIN.left}
          y={y(1000) + 16}
          className="fill-ink-faint text-[11px]"
        >
          R$ 1.000,00 no vencimento
        </text>

        <path d={area} className="fill-chart-received" opacity="0.1" />
        <path
          d={line}
          fill="none"
          className="stroke-chart-received"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />

        <circle
          cx={x(last.days)}
          cy={y(last.amount)}
          r="5"
          className="fill-chart-received stroke-surface"
          strokeWidth="2"
        />

        {/* A label only at the end: it is the number the page is asserting. */}
        <text
          x={x(last.days)}
          y={y(last.amount) - 16}
          textAnchor="end"
          className="fill-ink text-[15px] font-semibold"
        >
          R$ 1.061,21
        </text>

        {[0, 30, 60, 90].map((days) => (
          <text
            key={days}
            x={x(days)}
            y={HEIGHT - 12}
            textAnchor={days === 0 ? "start" : days === 90 ? "end" : "middle"}
            className="fill-ink-faint text-[11px]"
          >
            {days === 0 ? "vencimento" : `${days} dias`}
          </text>
        ))}
      </svg>
    </figure>
  );
}
