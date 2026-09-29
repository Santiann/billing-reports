import type {
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
} from "react";

/**
 * A form field: label, control, hint and error.
 *
 * `Field` wraps the three because the wiring between them is where accessibility
 * gets lost: `htmlFor` with no matching `id`, an error the screen reader never
 * announces, an invalid field with no `aria-invalid`. Tied together here, once, no
 * form has to remember.
 */

const CONTROL =
  "w-full rounded-md border border-rule-strong bg-surface px-3 py-2 text-sm " +
  "text-ink placeholder:text-ink-faint transition-colors " +
  "hover:border-ink-faint disabled:bg-sunken disabled:text-ink-muted " +
  "aria-[invalid=true]:border-overdue";

export function Input({
  className = "",
  ...props
}: InputHTMLAttributes<HTMLInputElement>) {
  return <input className={`${CONTROL} ${className}`.trim()} {...props} />;
}

export function Select({
  className = "",
  ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
  return <select className={`${CONTROL} ${className}`.trim()} {...props} />;
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
 * A field's validation error.
 *
 * `role="alert"` so the screen reader announces it without the user having to go
 * back to the field. It comes from the backend's 422, field by field, and not from
 * a parallel client-side validation that could disagree with the server's.
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
