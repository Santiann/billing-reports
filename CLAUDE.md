# Gerador de relatórios de faturamento

Aplicação de faturamento com autenticação e relatório de cobranças projetado para
tabelas na casa dos milhões de registros.

Este arquivo orienta os agentes de IA usados no desenvolvimento. Ele está no
repositório porque o teste pede que as instruções de agente sejam mantidas junto
do projeto.

---

## Stack — fixa

- **Backend:** PHP 8.3 + Laravel, API REST, Sanctum (token bearer)
- **Frontend:** Next.js (App Router) + TypeScript
- **Banco:** MySQL 8
- **Infra:** Docker + Docker Compose (mysql, php-fpm, nginx, frontend)

Nenhum item acima é substituível. São exigências do teste, não preferências.

---

## A regra que governa a arquitetura

**O valor atualizado de uma cobrança vencida tem que ser calculável em SQL.**

O relatório precisa ordenar por valor atualizado e somar o total de juros sobre o
conjunto filtrado inteiro. Se o cálculo existir apenas em PHP, qualquer uma
dessas duas operações obriga a carregar o resultado inteiro em memória — o que o
teste proíbe explicitamente.

Consequências, e elas são obrigatórias:

- `App\Domain\Billing\InterestCalculator` é a única fonte da regra e tem duas
  faces: `updatedAmountSql()` e `interestAmountSql()`, usadas em `selectRaw` na
  listagem e nas agregações, e `for(Billing $billing)`, usada para exibir uma
  cobrança isolada. `overdueSql()` mora junto porque "vencida" é a mesma regra
  vista de outro ângulo.
- A data de referência **desce do PHP**, nunca `CURDATE()`. `travelTo()` move o
  relógio do PHP e não o do MySQL: com `CURDATE()` embutido, o teste de
  consistência compararia tempo congelado contra tempo real e nunca fecharia.
  É também o que permite calcular juros na data do pagamento.
- Existe um teste que roda a mesma matriz de casos pelas duas faces e afirma
  igualdade até o centavo. **Sem esse teste a entrega está incompleta** — ele é o
  que sustenta a exigência de "resultado consistente em todas as telas e
  relatórios".
- Juros compostos: `valor_original * POW(1 + taxa_mensal, dias_atraso / 30)`.
- Cobrança paga não acumula juros. Os juros congelam na data do pagamento e o
  valor exibido vem das colunas gravadas no ato do pagamento, nunca de recálculo.

---

## Next.js — duas origens de API

Dentro do Compose existem dois contextos de fetch e eles **não** usam a mesma URL:

| Contexto | Base | Variável |
|---|---|---|
| Server Components, Route Handlers | `http://backend` | `API_URL_INTERNAL` |
| Código executando no browser | `http://localhost:8000` | `NEXT_PUBLIC_API_URL` |

Usar a variável errada é o bug mais provável deste projeto e ele só se manifesta
dentro do Docker. Todo fetch passa por `lib/api.ts`, que resolve a base pelo
contexto. Nenhum `fetch` com URL literal espalhado por componente.

No Compose, `backend` é o serviço **nginx** — é ele que responde HTTP. O
php-fpm se chama `php`, porque fala FastCGI e não HTTP. Não renomear: o
`http://backend` da tabela acima depende dessa escolha.

---

## Autenticação

Token Sanctum em cookie **httpOnly**, nunca em `localStorage`.

- `POST /api/auth/login` é um Route Handler do Next: chama o Laravel, recebe o
  token, grava o cookie httpOnly e não devolve o token ao browser.
- `middleware.ts` protege as rotas do app pela presença do cookie.
- Server Components leem o cookie e enviam `Authorization: Bearer` ao Laravel.
- **Downloads de PDF e CSV passam por Route Handler**, que anexa o token e faz
  stream da resposta do Laravel. O browser não tem o token, então não pode
  chamar o endpoint de exportação diretamente. O corpo é repassado sem ser
  lido: consumir o stream para reenviar guardaria o arquivo em memória.
- **Mutações usam Server Action**, não Route Handler. Mesma razão — quem fala
  com o Laravel é o servidor — mas a Action devolve os erros de validação campo
  a campo para o formulário, em vez de uma mensagem genérica. Route Handler
  fica para o que o browser precisa navegar ou baixar.

---

## Performance — não negociável

- Paginação, filtros e ordenação sempre no banco. Nenhum `->get()` seguido de
  filtro ou ordenação em coleção.
- Exportação CSV com `lazy()` + `StreamedResponse`, escrevendo linha a linha.
  Nunca montar o conjunto completo em array.
- Exportação PDF é limitada por natureza: o documento é montado inteiro antes
  de existir, então não há streaming. Teto de linhas com 422 acima dele,
  orientando o CSV. Decisão a documentar no README, não falha a esconder.
- **O teto é 1.000, não 5.000.** O valor original era estimativa e não
  sobreviveu à medição: o dompdf consome 420 MB para mil linhas, 1.164 MB para
  duas mil e estoura 3 GB em cinco mil — o crescimento é superlinear porque ele
  monta a árvore de frames da tabela toda antes de paginar. Vive em
  `config/reports.php` com a curva medida registrada ao lado.
- Totalizadores em query de agregação separada, sobre o conjunto filtrado
  inteiro. Nunca somar a página corrente.
- **Índices:** coluna de igualdade antes da coluna de range. Como o usuário
  escolhe qual das três datas define o período, cada uma precisa do seu próprio
  índice, e a variante com `customer_id` à frente cobre o caso de filtro por
  cliente. Cada índice criado vai para o README junto da query que ele serve.
- Seeder gera volume real (2M+ cobranças) por insert em lote com chunk, não por
  factory registro a registro.

---

## Testes

Ver `.claude/skills/laravel-report-tests/SKILL.md` para as convenções e as
armadilhas específicas deste projeto (tempo congelado, resposta em stream,
asserção sobre PDF).

**A suíte roda em MySQL, não em SQLite.** O skeleton do Laravel vem apontado
para `sqlite/:memory:`, e isso inviabilizaria o teste de consistência: em
SQLite a face SQL validaria outro motor — `POW()` nem existe por padrão. O
banco é o `faturamento_test`, criado pelo init do container, e a consequência é
que a suíte roda dentro dele:

```
docker compose exec php php artisan test
```

Regra geral: antes de escrever código de regra de negócio, escrever o teste que
ela precisa passar.

---

## Commits

Pequenos, semânticos, um por responsabilidade — o histórico faz parte da
avaliação. Um commit por vez, e o projeto sobe depois de cada um.

Ordem planejada:

```
chore: scaffold laravel and next apps
chore: add docker environment
chore: add ai agent configuration
feat: add authentication structure
feat: create database schema and factories
feat: create customers module
feat: create billing module
feat: add overdue interest calculation
feat: create billing report filters
feat: add report indexes
feat: add csv report export
feat: add pdf report export
test: add billing interest tests
docs: update project instructions
```

---

## Como trabalhar neste repositório

- Uma etapa por vez, na ordem acima. Não adiantar etapas nem encadear módulos.
- Ao terminar uma etapa, parar e apresentar o diff antes de seguir.
- Nenhuma decisão técnica fica só no código: se existe alternativa razoável, a
  escolha e o porquê vão para o README.
- Não implementar nada além do que o teste pede. Escopo extra não pontua e
  aumenta a superfície de erro.
- Não trocar biblioteca ou padrão sem registrar a decisão no README.
