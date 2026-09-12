"use client";

import { useEffect, useRef, useState } from "react";

import type { Customer } from "@/types/customer";
import type { Paginated } from "@/types/pagination";

type CustomerPickerProps = {
  name: string;
  defaultCustomer?: Customer;
  disabled?: boolean;
  hasError?: boolean;
};

/**
 * Seletor de cliente com busca.
 *
 * Um <select> com todos os clientes não escala — a base de teste tem cinco
 * mil. Aqui o usuário digita, a busca vai ao Route Handler (que anexa o token
 * no servidor) e só os primeiros resultados descem. O id selecionado viaja
 * num input escondido, então o formulário continua sendo um form comum e a
 * Server Action não precisa saber que existe um combobox.
 */
export function CustomerPicker({
  name,
  defaultCustomer,
  disabled,
  hasError,
}: CustomerPickerProps) {
  const [selected, setSelected] = useState<Customer | undefined>(defaultCustomer);
  const [term, setTerm] = useState("");
  const [results, setResults] = useState<Customer[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const controller = new AbortController();
    // Debounce: sem ele cada tecla vira uma requisição.
    const timer = setTimeout(async () => {
      setIsLoading(true);

      try {
        const response = await fetch(
          `/api/customers?search=${encodeURIComponent(term)}`,
          { signal: controller.signal },
        );

        if (response.ok) {
          const payload: Paginated<Customer> = await response.json();
          setResults(payload.data);
        }
      } catch {
        // Abortos de digitação caem aqui e não são erro.
      } finally {
        setIsLoading(false);
      }
    }, 300);

    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [term, isOpen]);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) {
        setIsOpen(false);
      }
    }

    document.addEventListener("mousedown", onClickOutside);

    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  return (
    <div ref={containerRef} className="relative">
      <input type="hidden" name={name} value={selected?.id ?? ""} />

      <input
        type="text"
        role="combobox"
        aria-expanded={isOpen}
        aria-controls="customer-picker-list"
        autoComplete="off"
        disabled={disabled}
        value={isOpen ? term : (selected?.name ?? "")}
        placeholder="Buscar cliente por nome, documento ou e-mail"
        onFocus={() => {
          setIsOpen(true);
          setTerm("");
        }}
        onChange={(event) => setTerm(event.target.value)}
        className={`w-full rounded-md border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-faint transition-colors disabled:bg-sunken disabled:text-ink-muted ${
          hasError
            ? "border-overdue"
            : "border-rule-strong hover:border-ink-faint"
        }`}
      />

      {isOpen ? (
        <ul
          id="customer-picker-list"
          role="listbox"
          className="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-rule bg-surface py-1 shadow-raised"
        >
          {isLoading ? (
            <li className="px-3 py-2 text-sm text-ink-muted">Buscando…</li>
          ) : results.length === 0 ? (
            <li className="px-3 py-2 text-sm text-ink-muted">
              Nenhum cliente encontrado.
            </li>
          ) : (
            results.map((customer) => (
              <li key={customer.id}>
                <button
                  type="button"
                  onClick={() => {
                    setSelected(customer);
                    setIsOpen(false);
                  }}
                  className="block w-full px-3 py-2 text-left text-sm transition-colors hover:bg-sunken"
                >
                  <span className="font-medium text-ink">
                    {customer.name}
                  </span>
                  <span className="block font-mono text-xs text-ink-muted">
                    {customer.document}
                  </span>
                </button>
              </li>
            ))
          )}
        </ul>
      ) : null}
    </div>
  );
}
