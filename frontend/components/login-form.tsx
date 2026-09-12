"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/ui/field";
import { login } from "@/lib/auth-client";

type LoginFormProps = {
  redirectTo: string;
};

export function LoginForm({ redirectTo }: LoginFormProps) {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setIsSubmitting(true);

    try {
      await login(email, password);
      // refresh() antes de push() para os Server Components relerem o cookie
      // recém-gravado; sem isso a home renderiza com a sessão antiga.
      router.refresh();
      router.push(redirectTo);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : "Erro inesperado.");
      setIsSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5" noValidate>
      <Field label="E-mail" htmlFor="email">
        <Input
          id="email"
          name="email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          disabled={isSubmitting}
        />
      </Field>

      <Field label="Senha" htmlFor="password">
        <Input
          id="password"
          name="password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          disabled={isSubmitting}
        />
      </Field>

      {/*
        Erro de credencial não é erro de campo: o 401 não diz qual dos dois
        está errado, de propósito, para não revelar quais e-mails existem.
        Por isso aparece acima do botão, e não sob um campo.
      */}
      {error ? (
        <p
          role="alert"
          className="rounded-md border border-overdue/30 bg-overdue-soft px-3 py-2 text-sm text-overdue"
        >
          {error}
        </p>
      ) : null}

      <Button type="submit" disabled={isSubmitting} className="w-full">
        {isSubmitting ? "Entrando…" : "Entrar"}
      </Button>
    </form>
  );
}
