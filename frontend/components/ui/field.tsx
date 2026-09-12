import type {
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
} from "react";

/**
 * Campo de formulário: rótulo, controle, dica e erro.
 *
 * O `Field` embrulha os três porque a ligação entre eles é onde acessibilidade
 * se perde: `htmlFor` sem `id` correspondente, erro que o leitor de tela nunca
 * anuncia, campo inválido sem `aria-invalid`. Amarrado aqui, uma vez, nenhum
 * formulário precisa lembrar.
 */

const CONTROLE =
  "w-full rounded-md border border-rule-strong bg-surface px-3 py-2 text-sm " +
  "text-ink placeholder:text-ink-faint transition-colors " +
  "hover:border-ink-faint disabled:bg-sunken disabled:text-ink-muted " +
  "aria-[invalid=true]:border-overdue";

export function Input({
  className = "",
  ...props
}: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={`${CONTROLE} ${className}`.trim()} {...props} />;
}

export function Select({
  className = "",
  ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select className={`${CONTROLE} ${className}`.trim()} {...props} />;
}

export function Field({
  label,
  htmlFor,
  hint,
  errors,
  required,
  children,
}: {
  label: string;
  htmlFor: string;
  hint?: string;
  errors?: string[];
  required?: boolean;
  children: ReactNode;
}) {
  const erroId = `${htmlFor}-erro`;
  const dicaId = `${htmlFor}-dica`;

  return (
    <div>
      <label
        htmlFor={htmlFor}
        className="mb-1.5 block text-sm font-medium text-ink"
      >
        {label}
        {required ? (
          <span className="ml-1 text-overdue" aria-hidden="true">
            *
          </span>
        ) : null}
      </label>

      {hint ? (
        <p id={dicaId} className="mb-1.5 text-xs text-ink-muted">
          {hint}
        </p>
      ) : null}

      {children}

      <FieldError id={erroId} messages={errors} />
    </div>
  );
}

/**
 * Erro de validação do campo.
 *
 * `role="alert"` para o leitor de tela anunciar sem o usuário precisar voltar
 * ao campo. Vem do 422 do backend, campo a campo, e não de uma validação
 * paralela no cliente que poderia discordar da do servidor.
 */
export function FieldError({
  id,
  messages,
}: {
  id?: string;
  messages?: string[];
}) {
  if (!messages || messages.length === 0) {
    return null;
  }

  return (
    <p id={id} role="alert" className="mt-1.5 text-xs text-overdue">
      {messages[0]}
    </p>
  );
}
