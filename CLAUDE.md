# Gerador de relatórios de faturamento

Aplicação de faturamento com autenticação e relatório de cobranças projetado para
tabelas na casa dos milhões de registros.

Este arquivo orienta os agentes de IA usados no desenvolvimento. Está
versionado junto do código de propósito: as instruções que produziram o
projeto fazem parte dele, e mudaram quando a medição contrariou o que diziam.

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
  orientando o CSV. Decisão a documentar em `docs/performance.md`, não falha a
  esconder.
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
  cliente. Cada índice criado vai para `docs/performance.md` junto da query que
  ele serve.
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

**Ponta a ponta com Playwright**, contra a stack em execução:

```
make e2e
```

Serviço próprio no Compose, com perfil `e2e` para não subir junto do resto.
Roda contra a aplicação como ela é entregue — Next falando com o nginx pelo
nome do serviço, sessão em cookie, MySQL de verdade —, e não contra um servidor
que o Playwright levante. Fica fora do CI de propósito: exigiria subir a stack
inteira no runner.

**CI em `.github/workflows/ci.yml`**: dois jobs paralelos rodando o que
`make test` e `make lint` fazem aqui. Um push que quebra o typecheck ou a suíte
aparece vermelho no PR.

---

## Commits

Pequenos, semânticos, um por responsabilidade — o histórico faz parte da
avaliação. Um commit por vez, e o projeto sobe depois de cada um.

### Etapa 1 — entregue

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

### Etapa 2 — diferenciais e acabamento

Três decisões valem para a etapa inteira e não se reabrem a cada commit:

- **Mesma branch.** Tudo vai para `main`. Não se abre branch por bloco: a
  etapa cresce em cima do que já está entregue.
- **Histórico preservado.** Os 14 commits da etapa 1 não são reescritos:
  mantêm o trailer `Co-Authored-By`, e os novos seguem com ele. Nada de
  rebase, squash ou amend sobre o que já foi empurrado.
- **A etapa 1 é a base, não rascunho.** O que já está entregue só muda quando
  o commit desta etapa pede — e aí a mudança é o assunto do commit.

Ordem em blocos. Um bloco não emenda no outro: cada commit para, mostra o diff
e espera.

**Blocos A a F — entregues.** A lista abaixo é o que aconteceu, não o que foi
planejado: quatro commits não estavam no plano e nasceram de achado durante a
etapa, e estão marcados.

```
A — fundação
docs: plan stage two
chore: add project skills
fix: seed paid billings with frozen interest
fix: agree on the half cent in both faces          <- achado: 1 divergência em 13.654
feat: add global error and loading boundaries
chore: add makefile
refactor: trim excessive comments

B — documentação da API
feat: add openapi specification
feat: serve api documentation at root

C — repaginação visual
feat: add design system foundation
refactor: restyle authentication and app shell
refactor: restyle customers and billings
refactor: restyle billing report
feat: add dashboard
chore: add readme and skill discovery skills

D — importação e landing page
feat: add customer csv import
feat: add billing csv import
feat: add public landing page

E — diferenciais técnicos
feat: add role based access control
feat: add payment idempotency
feat: add billing audit trail
feat: add payment reversal
feat: cache report totals
feat: add report explain command
feat: add rate limiting and structured logging
ci: add continuous integration pipeline
fix: generate next route types before the typecheck <- achado: o CI pegou o que
                                                      passava na máquina

F — qualidade
test: add end to end frontend tests
fix: make payment and reversal work outside localhost <- achado: o E2E pegou
                                                        crypto.randomUUID
chore: apply security review
docs: update project documentation
```

**Blocos G e H — restantes.**

```
G — subida do zero, cronometrada
perf: build report indexes after bulk seed
docs: document clean install timing

H — zerar a lista de pendências (docs/producao.md)
perf: size the innodb buffer pool
perf: add fulltext index for billing description
perf: export csv from raw rows
feat: replace pdf renderer with incremental writer
feat: add asynchronous export for large reports
perf: evaluate partitioning billings by date
feat: add read replica for report queries
docs: empty the pending list
```

O bloco H fecha o `docs/producao.md`. Uma
pendência morre de dois jeitos, e os dois valem: implementada, ou medida e
descartada com o número que embasou o descarte. O que não vale é continuar
listada como intenção.

---

## Como trabalhar neste repositório

- Uma etapa por vez, na ordem acima. Não adiantar etapas nem encadear módulos.
- Ao terminar uma etapa, parar e apresentar o diff antes de seguir.
- Nenhuma decisão técnica fica só no código: se existe alternativa razoável, a
  escolha e o porquê vão para a documentação. O README é a porta de entrada e
  fica curto — o detalhe vive em `docs/`, por tema. Decisão nova entra no
  documento do tema, e só sobe para o README se mudar o que quem revisa precisa
  saber nos primeiros três minutos.
- Não implementar nada além do que a especificação pede. Escopo extra não pontua e
  aumenta a superfície de erro. Na etapa 2 o "que a especificação pede" inclui a lista
  de diferenciais da especificação — e nada fora do commit da vez.
- Não trocar biblioteca ou padrão sem registrar a decisão em `docs/`.
- **Medir contra a base real, não contra a suíte.** O teto do PDF passou em
  todos os testes com um valor uma ordem de grandeza acima do possível, porque
  teste usa poucas linhas. Decisão sobre volume, limite ou performance exige
  medição fora da suíte, com os 2 milhões de registros carregados.
- **O que passa nesta máquina pode falhar em clone limpo.** O `make lint`
  passava com tipos de rota que o servidor de desenvolvimento havia gerado e que
  não existem num checkout novo. Quem pegou foi o CI. Verificação que depende de
  artefato gerado precisa gerá-lo.
- **Antes de teorizar, ler o log do container.** A hidratação não concluía nos
  testes de ponta a ponta, e o log do Next nomeava a opção que faltava. Foram
  duas execuções perdidas por não ter lido.
- **Ferramenta que varre `vendor/` mede a internet, não o projeto.** O scanner
  de vulnerabilidade acusou 23 achados críticos, todos em código minificado de
  terceiros. Relatório de ferramenta entra na documentação com o recorte
  explícito do que foi varrido.
- **Contexto seguro no browser.** `crypto.randomUUID()` e a família
  `crypto.subtle` só existem em HTTPS ou `localhost`. Código de cliente que
  dependa delas quebra em qualquer outro host servido por HTTP — e quebra
  silenciosamente, dentro do handler.
- **`--no-cache` não é "do zero".** `docker compose build --no-cache` ignora o
  cache de camadas, mas não baixa de novo a imagem base que já está no cache
  do BuildKit. Medição de instalação limpa precisa cronometrar esse download à
  parte, ou o número sai otimista sem aviso nenhum.
- **Número medido tem cópias.** Quando uma medição muda, procurar o número
  antigo em todo o repositório. A carga dos 2 milhões caiu de 51 para 46
  minutos no README e em `docs/performance.md`, e `docs/producao.md` seguiu
  dizendo 51 por um commit inteiro.
- **Ajuste que acelera a leitura pode atrasar a escrita.** O buffer pool de
  1 GB deixou as consultas do relatório 1,5 a 2 vezes mais rápidas e a carga
  dos 2 milhões 34% mais lenta nesta máquina. Mediu o ganho de um lado, medir o
  outro — e, quando o número estranho aparecer, rodar um controle na
  configuração antiga antes de culpar a máquina ou o ajuste.
