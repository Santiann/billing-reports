import type { DashboardMonth } from "@/types/dashboard";

/**
 * Collection rate per month: how much of what was billed turned into money.
 *
 * It comes out of the same twelve numbers as the chart above and costs no extra
 * query — but it answers a different question. The stacked chart shows volume;
 * this one shows collection efficiency, which is what you cannot see when billing
 * grows and received grows along with it.
 *
 * A line, because the reading is a trend. A single series, so no legend box: the
 * title already says what is plotted, and a little square would only repeat it.
 */

const WIDTH = 760;
const HEIGHT = 180;
const MARGIN = { top: 16, right: 44, bottom: 28, left: 44 };

export function CollectionChart({ months }: { months: DashboardMonth[] }) {
  const points = months.map((month) => {
    const billed = Number(month.original_amount);
    const received = Number(month.received_amount);

    return {
      label: month.label,
      month: month.month,
      rate: billed > 0 ? (received / billed) * 100 : 0,
    };
  });

  const areaWidth = WIDTH - MARGIN.left - MARGIN.right;
  const areaHeight = HEIGHT - MARGIN.top - MARGIN.bottom;
  const base = MARGIN.top + areaHeight;

  // A fixed 0 to 100 scale: the collection rate is a percentage, and stretching
  // the axis to the data's range would turn a two-point swing into a mountain.
  const x = (i: number) =>
    MARGIN.left + (areaWidth / Math.max(points.length - 1, 1)) * i;
  const y = (rate: number) => base - (rate / 100) * areaHeight;

  const path = points
    .map((point, i) => `${i === 0 ? "M" : "L"} ${x(i)} ${y(point.rate)}`)
    .join(" ");

  const last = points[points.length - 1];

  return (
    <figure className="m-0">
      {/* Scrolls horizontally on a narrow screen, like the tables do.
          The SVG scales the whole drawing, labels included: at 360px the 11px
          text would become 5px and the axis would be unreadable. Better to
          scroll the chart and keep the label at a size that reads — and whoever
          would rather not scroll has the table right below. */}
      <div className="overflow-x-auto">
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        className="w-full min-w-[560px]"
        role="img"
        aria-label="Taxa de recebimento por mês nos últimos doze meses"
      >
        {[0, 50, 100].map((tick) => (
          <g key={tick}>
            <line
              x1={MARGIN.left}
              x2={WIDTH - MARGIN.right}
              y1={y(tick)}
              y2={y(tick)}
              className="stroke-rule"
              strokeWidth="1"
            />
            <text
              x={MARGIN.left - 10}
              y={y(tick) + 4}
              textAnchor="end"
              className="fill-ink-faint text-[11px]"
            >
              {tick}%
            </text>
          </g>
        ))}

        <path
          d={path}
          fill="none"
          className="stroke-chart-received"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        />

        {points.map((point, i) => (
          <g key={point.month}>
            {/* A 2px ring in the surface colour: it keeps the point legible where
                it crosses the line. */}
            <circle
              cx={x(i)}
              cy={y(point.rate)}
              r="4.5"
              className="fill-chart-received stroke-surface"
              strokeWidth="2"
            />
            <title>{`${point.label}: ${point.rate.toFixed(1)}% recebido`}</title>
          </g>
        ))}

        {/* A direct label only at the end: a value on every point becomes noise. */}
        <text
          x={x(points.length - 1) + 10}
          y={y(last.rate) + 4}
          className="fill-ink text-[11px] font-medium"
        >
          {last.rate.toFixed(0)}%
        </text>

        {points.map((point, i) => (
          <text
            key={point.month}
            x={x(i)}
            y={HEIGHT - 8}
            textAnchor="middle"
            className="fill-ink-faint text-[11px]"
          >
            {point.label}
          </text>
        ))}
      </svg>
      </div>
    </figure>
  );
}
