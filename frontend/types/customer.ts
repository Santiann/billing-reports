export type CustomerStatus = "active" | "inactive";

export type Customer = {
  id: number;
  name: string;
  document: string;
  email: string;
  status: CustomerStatus;
  /** Rótulo traduzido, vindo do enum de PHP. */
  status_label: string;
  created_at: string | null;
};

export const CUSTOMER_STATUSES: ReadonlyArray<{
  value: CustomerStatus;
  label: string;
}> = [
  { value: "active", label: "Ativo" },
  { value: "inactive", label: "Inativo" },
];
