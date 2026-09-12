import type { ButtonHTMLAttributes } from "react";

/**
 * Botão, e as classes dele em separado.
 *
 * A separação existe porque metade dos "botões" desta aplicação são links: ir
 * para o cadastro, voltar para a listagem, editar. Um componente polimórfico
 * com `as` resolveria, ao custo de tipagem que ninguém lê. Expor as classes
 * deixa `<Link className={buttonClasses()}>` funcionar sem inventar abstração.
 */

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger";
export type ButtonSize = "sm" | "md";

const BASE =
  "inline-flex items-center justify-center gap-2 rounded-md border font-medium " +
  "transition-colors " +
  // Desabilitado ganha tratamento próprio em vez de opacidade. Meia opacidade
  // sobre tinta cheia produz um cinza sólido que, no tema escuro, parece mais
  // ativo do que o botão fantasma ao lado — visto na tela antes de virar
  // commit. Fundo rebaixado e tinta fraca não têm essa ambiguidade.
  "disabled:pointer-events-none disabled:border-rule disabled:bg-sunken " +
  "disabled:text-ink-faint disabled:shadow-none";

const VARIANTS: Record<ButtonVariant, string> = {
  // Tinta cheia: a ação principal da tela, uma por tela.
  primary: "border-ink bg-ink text-paper hover:bg-ink-muted hover:border-ink-muted",
  secondary: "border-rule-strong bg-surface text-ink hover:bg-sunken",
  ghost: "border-transparent bg-transparent text-ink-muted hover:bg-sunken hover:text-ink",
  // Destrutivo é a cor de vencida: no domínio, vermelho já significa perda.
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
      // `type` default "button": o default do HTML é "submit", e um botão
      // solto dentro de form envia o formulário sem ninguém pedir.
      type={type}
      className={buttonClasses({ variant, size, className })}
      {...props}
    />
  );
}
