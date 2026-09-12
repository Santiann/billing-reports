"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { Button } from "@/components/ui/button";
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
    <Button variant="secondary" size="sm" onClick={handleClick} disabled={isPending}>
      {isPending ? "Saindo…" : "Sair"}
    </Button>
  );
}
