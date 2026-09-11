import Link from "next/link";

export default function HomePage() {
  return (
    <div className="max-w-2xl">
      <h1 className="text-xl font-semibold text-slate-900">Início</h1>

      <p className="mt-2 text-slate-600">
        Cadastro de clientes e cobranças, com relatório de faturamento por
        período.
      </p>

      <Link
        href="/clientes"
        className="mt-6 inline-block rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
      >
        Ver clientes
      </Link>

      <p className="mt-8 text-sm text-slate-500">
        Cobranças e relatório entram nas próximas etapas.
      </p>
    </div>
  );
}
