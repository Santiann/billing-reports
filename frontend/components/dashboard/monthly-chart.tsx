import { formatCurrency } from "@/lib/format";
import type { DashboardMonth } from "@/types/dashboard";

/**
 * Billed per month, split between what came in and what is still to come.
 *
 * A stacked column because the question is part-to-whole over time: the full
 * height is the month's billed amount, and the cut shows how much of it turned
 * into money. Two bars side by side would answer "which is bigger", which is not
 * the question.
 *
 * The SVG is built on the server, with no JavaScript. The chart has no state — it
 * is a figure of twelve numbers — and the interaction it needs (highlight the
 * column under the cursor and show the value) CSS handles on its own.
 */

const WIDTH = 760;
const HEIGHT = 260;
const MARGIN = { top: 16, right: 8, bottom: 28, left: 68 };

/** A cap rounded upwards, so the axis gets a clean number. */
function roundCap(value: number): number {
  if (value <= 0) {
    return 1;
  }

  const magnitude = 10 ** Math.floor(Math.log10(value));
  return Math.ceil(value / (magnitude / 2)) * (magnitude / 2);
}

function compact(value: number): string {
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toLocaleString("pt-BR", { maximumFractionDigits: 1 })} mi`;
  }

  if (value >= 1_000) {
    return `${Math.round(value / 1_000).toLocaleString("pt-BR")} mil`;
  }

  return value.toLocaleString("pt-BR");
}

export function MonthlyChart({ months }: { months: DashboardMonth[] }) {
  const data = months.map((month) => ({
    ...month,
    billed: Number(month.original_amount),
    received: Number(month.received_amount),
  }));

  const cap = roundCap(Math.max(...data.map((d) => d.billed)));
  const areaWidth = WIDTH - MARGIN.left - MARGIN.right;
  const areaHeight = HEIGHT - MARGIN.top - MARGIN.bottom;
  const base = MARGIN.top + areaHeight;

  const band = areaWidth / data.length;
  // A 24px cap on the column: whatever is left of the band is air, on purpose.
  const width = Math.min(24, band * 0.5);

  const y = (value: number) => base - (value / cap) * areaHeight;
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => cap * f);

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
        className="w-full min-w-[640px]"
        role="img"
        aria-label="Faturado e recebido por mês nos últimos doze meses"
      >
        {/* Gridlines: hairline, solid, inset. Never dashed. */}
        {ticks.map((tick) => (
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
              {compact(tick)}
            </text>
          </g>
        ))}

        {data.map((month, i) => {
          const center = MARGIN.left + band * i + band / 2;
          const x = center - width / 2;

          const receivedHeight = Math.max(base - y(month.received), 0);
          const stackTop = y(month.billed);
          // 2px of breathing room IN THE BACKGROUND COLOUR separate the two
          // segments. It is the gap that separates, not an outline — an outline
          // adds ink that is not data.
          const receivableHeight = Math.max(y(month.received) - stackTop - 2, 0);

          return (
            <g key={month.month} className="group">
              {/* An invisible hover target covering the whole band: the column
                  alone is too narrow to aim at with a mouse. */}
              <rect
                x={MARGIN.left + band * i}
                y={MARGIN.top}
                width={band}
                height={areaHeight}
                fill="transparent"
              />

              {/* Receivable, on top. A 4px rounded end only here: it is where the
                  data ends, and the base stays square. */}
              <path
                d={roundedOnTop(x, stackTop, width, receivableHeight, 4)}
                className="fill-chart-pending transition-opacity group-hover:opacity-80"
              />

              {/* Received, anchored on the baseline. */}
              <rect
                x={x}
                y={y(month.received)}
                width={width}
                height={receivedHeight}
                className="fill-chart-received transition-opacity group-hover:opacity-80"
              />

              <text
                x={center}
                y={HEIGHT - 8}
                textAnchor="middle"
                className="fill-ink-faint text-[11px]"
              >
                {month.label}
              </text>

              {/* The value of the month under the cursor. A label on every month
                  would be unreadable; this one appears one at a time. */}
              <text
                x={center}
                y={stackTop - 8}
                textAnchor="middle"
                className="fill-ink text-[11px] font-medium opacity-0 transition-opacity group-hover:opacity-100"
              >
                {compact(month.billed)}
              </text>

              <title>
                {`${month.label}: ${formatCurrency(month.original_amount)} faturado, ${formatCurrency(month.received_amount)} recebido`}
              </title>
            </g>
          );
        })}

        <line
          x1={MARGIN.left}
          x2={WIDTH - MARGIN.right}
          y1={base}
          y2={base}
          className="stroke-rule-strong"
          strokeWidth="1"
        />
      </svg>
      </div>

      {/* Legend: mandatory with two series. The colour lives in the square next
          to the text, never in the text — green and amber do not read as ink. */}
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
 * A rectangle with the two top corners rounded.
 *
 * SVG has no per-corner radius, and `rx` on a `<rect>` would round the base too —
 * where the segment meets the one below it. Zero height becomes an empty path.
 */
function roundedOnTop(
  x: number,
  y: number,
  width: number,
  height: number,
  radius: number,
): string {
  if (height <= 0) {
    return "";
  }

  const r = Math.min(radius, height, width / 2);

  return [
    `M ${x} ${y + height}`,
    `L ${x} ${y + r}`,
    `Q ${x} ${y} ${x + r} ${y}`,
    `L ${x + width - r} ${y}`,
    `Q ${x + width} ${y} ${x + width} ${y + r}`,
    `L ${x + width} ${y + height}`,
    "Z",
  ].join(" ");
}
