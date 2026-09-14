# Testes

[← README](../README.md)

- [Testes](#testes)
- [Testes de ponta a ponta](#testes-de-ponta-a-ponta)

## Testes

```bash
make test                                   # docker compose exec php php artisan test
```

A suíte roda **dentro do container** porque roda em **MySQL**, não em SQLite.
O skeleton do Laravel vem apontado para `sqlite/:memory:`, e isso seria um
problema grave neste projeto: a regra central é que o valor atualizado de uma
cobrança seja calculável em SQL, e o teste de consistência obrigatório compara
a face SQL do `InterestCalculator` com a face PHP. Em SQLite ele estaria
validando outro motor — `POW()` nem existe por padrão, e `DATEDIFF()` e a
precisão de `DECIMAL` divergem.

```
OK (278 tests, 1055 assertions)
```

### Cobertura

```bash
make coverage                               # docker compose exec php php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text
```

| | |
|---|---|
| **Linhas** | **97,44%** (1333/1368) |
| Métodos | 90,87% (189/208) |
| Classes | 81,36% (48/59) |

Usa **pcov**, não xdebug: ele existe só para cobertura e custa uma fração do
tempo. Fica desligado por padrão (`pcov.enabled = 0`) para não pesar na
execução normal, e é ligado na linha de comando.

O `-d` precisa ir direto no `phpunit` porque `artisan test --coverage` roda o
PHPUnit em subprocesso e a flag não propaga — ele responde
"No code coverage driver available" mesmo com a extensão carregada.

A etapa 1 fechou com 99,84% de linhas sobre 628. A etapa 2 triplicou o código
coberto — 1.368 linhas — e a cobertura caiu 2,4 pontos. As 35 linhas
descobertas não estão espalhadas: elas se concentram nas classes novas, e quase
todas são **ramos de defesa**.

| Classe | Linhas |
|---|---|
| `IdempotentRequest` | 83,02% (44/53) |
| `IdempotencyStore` | 85,45% (47/55) |
| `CsvReader` | 90,20% (46/51) |
| `BillingAudit` | 91,67% (11/12) |
| `ExplainReportCommand` | 95,37% (103/108) |
| `BillingAuditObserver` | 95,45% (21/22) |
| `BillingAuditResource` | 96,15% (25/26) |
| `BillingCsvImport` | 98,00% (98/100) |
| `DashboardQuery` | 98,55% (68/69) |
| `BillingReportCsvExport` | 98,63% (72/73) |

O que está descoberto, nomeado:

- **A devolução da chave de idempotência no erro 500.** Cobri-la exigiria
  forçar um erro de servidor no meio de uma requisição com chave — encenação
  que testaria o teste, não o sistema.
- **A limpeza das chaves vencidas por sorteio.** Ela roda numa chance em
  duzentas, de propósito; um teste que a force teria de fixar o sorteio, e aí
  afirma sobre a fixação.
- **O `deleted()` dos observers.** Nada no sistema apaga cobrança. O gancho
  existe para o dia em que apagar, e é isso que o deixa descoberto.
- **A guarda de resposta em stream e o teto de 255 caracteres da chave.** Duas
  defesas para uso futuro do middleware de idempotência, que hoje vale para
  duas rotas que não exportam arquivo.
- **O rótulo de campo desconhecido na trilha.** Aparece só se uma coluna nova
  chegar sem rótulo — existe para a trilha não perder a alteração em silêncio.
- **O `flush()` da exportação CSV**, a cada 500 linhas escritas: exigiria criar
  500 cobranças para afirmar um efeito colateral sem resultado observável.

É a mesma decisão da etapa 1, com mais casos: perseguir o último ponto
percentual aqui produziria testes que provam encenação. O que esses ramos têm
em comum é serem o caminho do erro — e o caminho do erro que importa, aquele em
que o sistema **recusa** a operação, tem teste: [sem trilha não há
alteração](modulos.md#atômica-sem-trilha-sem-alteração), [chave repetida devolve o
primeiro resultado](modulos.md#idempotência-no-pagamento), [perfil de consulta não
escreve](modulos.md#a-barreira-é-o-backend-não-a-tela).

O relatório de cobertura foi o que expôs três lacunas reais, que já estão
fechadas: o filtro `status=pending` do relatório nunca era exercitado (os
testes usavam `paid` e `overdue` e pulavam o terceiro), três rótulos de
cabeçalho da exportação nunca eram gerados, e o ramo defensivo do calculador
para cobrança paga sem data de pagamento não tinha teste.

### O teste do seeder não emite DDL

`BillingVolumeSeederTest` roda o seeder de verdade sobre uma amostra de 600
cobranças e não limpa nada depois: quem desfaz é o rollback do
`RefreshDatabase`. Limpar com `TRUNCATE` seria o caminho óbvio e custaria caro.

`TRUNCATE` é DDL, e em MySQL DDL faz **commit implícito**. O Laravel percebe
que a transação do teste sumiu e marca `RefreshDatabaseState::$migrated =
false` — o que dispara um `migrate:fresh` inteiro antes de **cada teste
seguinte**, e não só dos desta classe. O código está em
`RefreshDatabase.php:158`, e o efeito foi medido aqui:

| | duração da classe |
|---|---|
| Com `TRUNCATE` no teardown | 360s (6 testes, ~50s de `migrate:fresh` cada) |
| Sem DDL nenhum | 62s (60s do `migrate:fresh` único + 0,3s por teste) |

É a mesma razão pela qual o seeder sai cedo quando não há o que truncar: em
tabela vazia, o `TRUNCATE` só teria o custo.

### Banco de testes

O banco da suíte é o `faturamento_test`, separado do de desenvolvimento porque
`RefreshDatabase` derruba e recria o schema a cada execução. Ele é criado no
first-init do MySQL por `docker/mysql/init/01-create-test-database.sql`. Em um
volume que já existe, o init script não roda — aplique o arquivo à mão:

```bash
docker compose exec -T mysql mysql -u root -proot < docker/mysql/init/01-create-test-database.sql
```

---

## Testes de ponta a ponta

```bash
make e2e          # docker compose --profile e2e run --rm e2e
```

Playwright cobrindo o que o enunciado pede como diferencial de frontend: login,
cadastro de cliente, registro de pagamento — mais o estorno — e exportação.
**10 testes, 3,4 minutos.**

Eles rodam contra a **stack do Compose**, e não contra um servidor que o
Playwright sobe. É deliberado: o que se quer provar é a aplicação como ela é
entregue — o Next falando com o nginx pelo nome do serviço, a sessão num cookie
httpOnly, o MySQL de verdade. Um `webServer` do Playwright subiria um Next
isolado, sem backend, e os fluxos de cadastro e pagamento não existiriam.

| Decisão | Por quê |
|---|---|
| Serviço no Compose com perfil `e2e` | `docker compose up -d` não sobe o que roda e termina |
| Imagem oficial do Playwright | a do frontend é Alpine, e os navegadores do projeto são compilados contra glibc |
| Um worker, sem paralelismo | os testes escrevem no MESMO banco; dois cadastros ao mesmo tempo disputariam a unique do documento |
| Sufixo único por execução | o banco não é limpo entre execuções, e sem isso a segunda rodada do dia falharia por conflito |
| Fora do CI | exigiria subir MySQL, php-fpm, nginx e Next no runner; o CI roda a suíte e o lint |

Dois testes rodam numa viewport de **360px** e afirmam a ausência de rolagem
horizontal — nas telas públicas e nas autenticadas. É o critério de aceite do
enunciado para telas pequenas, verificado por teste em vez de por captura.

### Quatro problemas reais que ele encontrou

Esta é a parte que justifica a suíte. Na primeira execução, **8 dos 10 testes
falharam** — e nenhum por causa do Playwright.

**1. O Next bloqueava a hidratação, e o log dizia isso.** Os testes chegam por
`http://frontend:3000`, o nome do serviço. O Next recusa requisições a recursos
de desenvolvimento vindas de host diferente daquele em que subiu, o HMR era
negado, a hidratação não concluía e **nenhum formulário respondia a clique**. O
container imprimia a opção pelo nome — `allowedDevOrigins` — e eu não havia
lido o log. Corrigido no `next.config.ts`, que vale só em desenvolvimento.

**2. O botão de pagar estava morto fora do `localhost`.** `crypto.randomUUID()`,
usado para sortear a chave de idempotência, existe **apenas em contexto
seguro**: HTTPS, ou `localhost` por exceção do browser. Servida por HTTP simples
em qualquer outro host — um IP na rede interna, o nome de um serviço —, a função
é `undefined`, o handler morria com `TypeError` antes de enviar e a tela não
dava aviso nenhum. Medido no container: `isSecureContext: false`,
`randomUUID: undefined`, `getRandomValues: function`. A correção monta o UUID v4
com `getRandomValues`, que não tem a restrição, e mantém aleatoriedade
criptográfica nos dois caminhos.

Este é o tipo de defeito que nenhum teste de unidade acha e nenhum clique em
`localhost` revela.

**3. A tela mostrava a mensagem de sucesso errada.** O código `sucesso=criado`
servia a cliente e a cobrança, e as duas listas renderizam o mesmo componente
de aviso: cadastrar uma **cobrança** exibia *"Cliente cadastrado com sucesso"*.
Agora são dois códigos, e `editado` continua servindo aos dois porque a mensagem
dele não nomeia entidade.

**4. A espera certa não é tempo, é hidratação.** O formulário de login é
controlado (`value` + `onChange`). Antes de o React assumir, preencher grava no
DOM um valor que a hidratação descarta, e clicar dispara o **envio nativo** do
formulário, que recarrega a página limpa — era exatamente o que o retrato de
falha mostrava. Os testes esperam pela chave que o React DOM pendura no nó
(`__reactProps$`) quando passa a tratar os eventos dele. É API interna do React,
e por isso só aparece no teste; a alternativa era `waitForTimeout`, que troca
uma corrida por uma aposta.

### Duas coisas que a suíte exigiu do ambiente

**Aquecimento das rotas.** O alvo é o servidor de desenvolvimento, que compila
cada rota na primeira visita — e a primeira visita é justamente o que estes
testes fazem. Três testes falharam por tempo, com o botão "Entrando…" ainda
desabilitado no retrato. Um `globalSetup` entra uma vez e visita as telas antes
da suíte, então cada teste mede a aplicação em vez do compilador.

**Ignorar a saída do Playwright no ESLint.** O relatório em HTML embute um
bundle minificado, e o `eslint` passou a analisá-lo: 3.054 problemas em código
que não é nosso.
