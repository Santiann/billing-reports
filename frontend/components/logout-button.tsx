"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { logout } from "@/lib/auth-client";

export function LogoutButton() {
  const router = useRouter();
  const [isPending, setIsPending] = useState(false);

  async function handleClick() {
    setIsPending(true);

    try {
      await logout();
      router.refresh();
      router.push("/login");
    } catch {
      // O cookie é apagado pelo handler mesmo se a API falhar, então mandar
      // para o login é a ação correta nos dois casos.
      router.push("/login");
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={isPending}
      className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
    >
      {isPending ? "Saindo…" : "Sair"}
    </button>
  );
}
