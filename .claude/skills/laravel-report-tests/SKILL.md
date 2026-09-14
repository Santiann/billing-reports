---
name: laravel-report-tests
description: Convenções e armadilhas para escrever os testes automatizados do backend deste projeto (Pest/PHPUnit, Laravel, MySQL). Use sempre que a tarefa envolver escrever, corrigir ou revisar teste — de cálculo de juros, de filtros ou totalizadores do relatório, de registro de pagamento, de exportação CSV ou PDF, ou de proteção de rota e endpoint. Use também quando a tarefa for implementar uma regra de negócio, porque o teste vem antes do código.
---

# Testes do backend

Os cenários mínimos são definidos pelo teste técnico e todos precisam existir:

1. Usuário não autenticado não acessa o relatório
2. Usuário não autenticado não exporta relatórios
3. Cálculo de juros para cobrança vencida
4. Cobrança paga não continua acumulando juros
5. Filtros do relatório
6. Totalizadores do relatório
7. Registro de pagamento
8. Exportação do relatório em PDF
9. Exportação do relatório em CSV

Além desses, o teste de consistência descrito abaixo é obrigatório neste projeto.

---

## Armadilha 1 — tempo

O cálculo de juros depende da data atual. Um teste que passa hoje e quebra amanhã
não é teste.

**Todo teste que toca juros congela o tempo com `travelTo()`** e monta as datas
relativas a esse ponto. Nunca usar `now()` implícito na montagem do cenário nem
datas literais de calendário.

O cenário da cobrança paga precisa avançar o relógio **depois** do pagamento e
afirmar que o valor não mudou. Sem esse avanço o teste não prova nada: ele
passaria mesmo com a regra errada.

---

## Armadilha 2 — resposta em stream

A exportação CSV devolve `StreamedResponse`. `assertSee` e `getContent()` não
funcionam nela — o corpo só existe quando o callback roda.

Capturar com `$response->streamedContent()` e só então afirmar sobre o conteúdo:
cabeçalho presente, número de linhas, o período e os filtros aplicados no topo do
arquivo, e os totalizadores no rodapé.

Afirmar também que a exportação respeita o filtro: gerar um conjunto onde parte
dos registros está fora do filtro e verificar que eles não aparecem. Contar
linhas não basta.

---

## Armadilha 3 — PDF

Não afirmar sobre o binário do PDF. Testar o que é verificável e estável:

- status 200 e `Content-Type: application/pdf`
- o teto de linhas dispara 422 quando o conjunto filtrado excede o limite, com
  mensagem orientando o CSV
- abaixo do teto, não dispara

O teste do teto é o mais importante dos três: é ele que documenta a decisão de
projeto sobre exportação em volume.

---

## Armadilha 4 — o teste de consistência

`InterestCalculator` tem duas faces (SQL e PHP) e elas podem divergir
silenciosamente — arredondamento, precisão de `DECIMAL` contra float, contagem de
dias.

Escrever um teste com matriz de casos que percorra:

- cobrança em dia, vencida por 1 dia, por 30, por 400
- taxa zero, taxa alta
- valor com centavos quebrados
- cobrança paga em dia e paga em atraso

Para cada caso, afirmar que a face SQL e a face PHP devolvem o mesmo valor até o
centavo. Este é o teste que sustenta a exigência de resultado consistente entre
telas e relatório, e é o primeiro que um avaliador vai procurar.

---

## Armadilha 5 — totalizadores

Os totalizadores vêm de query de agregação separada, sobre o conjunto filtrado
inteiro. O erro fácil é o teste passar somando a primeira página.

Montar cenário com mais registros do que cabe numa página e afirmar que os
totais correspondem ao conjunto inteiro, não à página. Sem isso o teste não
cobre a regra que ele diz cobrir.

---

## Armadilha 6 — DDL dentro do teste

`TRUNCATE`, `ALTER` e qualquer outro DDL fazem **commit implícito** em MySQL. A
transação que o `RefreshDatabase` abriu morre ali, o Laravel detecta que ela
sumiu e marca `RefreshDatabaseState::$migrated = false` — o que dispara um
`migrate:fresh` inteiro antes de **cada teste seguinte da suíte**, não só dos
da classe culpada. Medido neste projeto: ~50s por teste contra 0,3s.

Nunca limpar tabela no teardown. Quem limpa é o rollback do `RefreshDatabase`.
Um seeder invocado de dentro do teste também não pode truncar — o
`BillingVolumeSeeder` sai cedo quando as tabelas já estão vazias justamente por
isso.

---

## Armadilha 7 — `withHeaders()` vale para o resto do teste

`$this->withHeaders([...])` não é da próxima requisição: ele guarda o cabeçalho
para **todas** as requisições seguintes do mesmo teste. Num teste de uma
chamada só não faz diferença, e é por isso que passa despercebido.

Com `Idempotency-Key` é fatal. Pagar com a chave e depois estornar "sem chave"
manda a chave do pagamento junto com o estorno — outro caminho, outra impressão
digital — e o middleware responde 422 de chave reaproveitada. O teste falha
pelo motivo errado, ou pior, passa pelo motivo errado.

Cabeçalho que muda entre requisições vai no argumento da própria chamada:

```php
$this->postJson($uri, $corpo, ['Idempotency-Key' => $chave]);
```

---

## Convenções

- Feature tests para tudo que passa por HTTP; unit test apenas para o
  `InterestCalculator`. Duas exceções conscientes: `InterestCalculatorTest`
  fica em `tests/Unit` mas toca o banco, porque a face SQL só existe dentro do
  MySQL; e `ReportIndexTest` fica em `tests/Feature` sem passar por HTTP,
  porque o que ele verifica é o schema.
- A suíte roda em **MySQL**, no banco `faturamento_test`. Em SQLite a face SQL
  do calculador validaria outro motor — `POW()` nem existe por padrão.
- `RefreshDatabase`, e factories com states nomeados (`overdue()`, `paid()`,
  `paidLate()`) em vez de montar datas na mão dentro de cada teste.
- Um comportamento por teste, com nome descrevendo a regra e não o método.
- Nos testes de autenticação, cobrir os dois lados: sem token responde 401, e
  com token responde 200. Só o 401 não prova que a rota funciona.
- Volume nos testes é pequeno de propósito. A prova de performance é o seeder e
  a documentação de índices, não a suíte.
- Consequência disso: **teste pequeno não prova comportamento em volume.** O
  teto do PDF passou na suíte com o valor errado, e só a exportação contra a
  base real mostrou que ele estava uma ordem de grandeza acima do possível.
  Toda decisão sobre volume precisa ser medida fora da suíte.
- Asserção sobre plano de execução (`EXPLAIN`) é sobre `possible_keys`, não
  sobre o plano escolhido: em tabela pequena o otimizador prefere varredura, e
  afirmar `type != ALL` falharia por motivo errado.
