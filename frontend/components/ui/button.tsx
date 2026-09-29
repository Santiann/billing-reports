import type { ButtonHTMLAttributes } from "react";

/**
 * The button, and its classes separately.
 *
 * The split exists because half the "buttons" in this application are links: go to
 * the create form, back to the listing, edit. A polymorphic component with `as`
 * would solve it, at the cost of typing nobody reads. Exposing the classes lets
 * `<Link className={buttonClasses()}>` work without inventing an abstraction.
 */

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger";
export type ButtonSize = "sm" | "md";

const BASE =
  "inline-flex items-center justify-center gap-2 rounded-md border font-medium " +
  "transition-colors " +
  // Disabled gets its own treatment rather than opacity. Half opacity over full
  // ink produces a solid grey that, in the dark theme, looks more active than the
  // ghost button next to it — seen on screen before it became a commit. A sunken
  // background and weak ink carry no such ambiguity.
  "disabled:pointer-events-none disabled:border-rule disabled:bg-sunken " +
  "disabled:text-ink-faint disabled:shadow-none";

const VARIANTS: Record<ButtonVariant, string> = {
  // Full ink: the screen's primary action, one per screen.
  primary: "border-ink bg-ink text-paper hover:bg-ink-muted hover:border-ink-muted",
  secondary: "border-rule-strong bg-surface text-ink hover:bg-sunken",
  ghost: "border-transparent bg-transparent text-ink-muted hover:bg-sunken hover:text-ink",
  // Destructive is the overdue colour: in this domain, red already means loss.
  danger: "border-overdue/30 bg-overdue-soft text-overdue hover:border-overdue/60",
};

const SIZES: Record<ButtonSize, string> = {
  sm: "px-3 py-1.5 text-xs",
  md: "px-4 py-2 text-sm",
};

export function buttonClasses({
  variant = "primary",
  size = "md",
  className = "",
}: {
  variant?: ButtonVariant;
  size?: ButtonSize;
  className?: string;
} = {}): string {
  return `${BASE} ${VARIANTS[variant]} ${SIZES[size]} ${className}`.trim();
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
};

export function Button({
  variant,
  size,
  className,
  type = "button",
  ...props
}: ButtonProps) {
  return (
    <button
      // `type` defaults to "button": HTML's default is "submit", and a loose
      // button inside a form submits it without anyone asking.
      type={type}
      className={buttonClasses({ variant, size, className })}
      {...props}
    />
  );
}
