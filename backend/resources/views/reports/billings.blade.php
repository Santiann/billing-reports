<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório de faturamento</title>
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #0f172a; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .meta { color: #475569; font-size: 8px; margin-bottom: 10px; }
        .meta strong { color: #0f172a; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #f1f5f9; border-bottom: 1px solid #cbd5e1;
            text-align: left; padding: 4px 5px; font-size: 8px;
        }
        tbody td { border-bottom: 1px solid #e2e8f0; padding: 3px 5px; }
        .num { text-align: right; }
        .overdue { color: #b91c1c; }
        tfoot td {
            border-top: 2px solid #cbd5e1; padding: 5px; font-weight: bold;
            background: #f8fafc;
        }
        .totals { margin-top: 12px; width: 100%; border-collapse: collapse; }
        .totals td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; }
        .totals .label { color: #475569; }
        .totals .value { text-align: right; font-weight: bold; }
        .empty { padding: 18px; text-align: center; color: #64748b; }
    </style>
</head>
<body>
    <h1>Relatório de faturamento</h1>

    {{-- Período e filtros aplicados: o arquivo precisa se explicar sozinho,
         porque quem recebe não viu a tela que o gerou. --}}
    <div class="meta">
        <strong>Período baseado em:</strong> {{ $filters->dateFieldLabel() }} &nbsp;|&nbsp;
        <strong>Período:</strong> {{ $period }} &nbsp;|&nbsp;
        <strong>Status:</strong> {{ $filters->statusLabel() }} &nbsp;|&nbsp;
        <strong>Cliente:</strong> {{ $customerName }}<br>
        <strong>Gerado em:</strong> {{ $generatedAt }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th>Descrição</th>
                <th>Emissão</th>
                <th>Vencimento</th>
                <th>Status</th>
                <th class="num">Valor original</th>
                <th class="num">Juros</th>
                <th class="num">Atualizado</th>
                <th class="num">Pago</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($billings as $billing)
                <tr>
                    <td>{{ $billing->customer?->name }}</td>
                    <td>{{ $billing->description }}</td>
                    <td>{{ $billing->issue_date->format('d/m/Y') }}</td>
                    <td>{{ $billing->due_date->format('d/m/Y') }}</td>
                    <td class="{{ $billing->isOverdue() ? 'overdue' : '' }}">
                        {{ $billing->isOverdue() ? 'Vencida' : $billing->status->label() }}
                    </td>
                    <td class="num">{{ $money($billing->getAttribute('original_amount')) }}</td>
                    <td class="num {{ (float) $billing->getAttribute('interest_amount') > 0 ? 'overdue' : '' }}">
                        {{ $money($billing->getAttribute('interest_amount')) }}
                    </td>
                    <td class="num">{{ $money($billing->getAttribute('updated_amount')) }}</td>
                    <td class="num">{{ $billing->paid_amount === null ? '—' : $money($billing->paid_amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">Nenhuma cobrança no período e filtros selecionados.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Totalizadores da consulta de agregação, sobre o conjunto filtrado
         inteiro — os mesmos que a tela e o CSV exibem. --}}
    <table class="totals">
        <tr>
            <td class="label">Quantidade de cobranças</td>
            <td class="value">{{ number_format($totals['count'], 0, ',', '.') }}</td>
            <td class="label">Valor original total</td>
            <td class="value">{{ $money($totals['original_amount']) }}</td>
        </tr>
        <tr>
            <td class="label">Total de juros</td>
            <td class="value">{{ $money($totals['interest_amount']) }}</td>
            <td class="label">Valor atualizado total</td>
            <td class="value">{{ $money($totals['updated_amount']) }}</td>
        </tr>
        <tr>
            <td class="label">Valor total recebido</td>
            <td class="value">{{ $money($totals['paid_amount']) }}</td>
            <td class="label">Valor total pendente</td>
            <td class="value">{{ $money($totals['pending_amount']) }}</td>
        </tr>
    </table>
</body>
</html>
