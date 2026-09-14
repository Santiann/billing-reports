# Gerador de Relatórios de Faturamento

Aplicação de faturamento com autenticação e relatório de cobranças projetado
para tabelas na casa dos milhões de registros.

[![CI](https://github.com/Santiann/billing-reports/actions/workflows/ci.yml/badge.svg)](https://github.com/Santiann/billing-reports/actions/workflows/ci.yml)

| | |
|---|---|
| **Backend** | PHP 8.3 + Laravel 13 (API REST) |
| **Frontend** | Next.js 16 (App Router) + TypeScript |
| **Banco** | MySQL 8 |
| **Infra** | Docker + Docker Compose |

O enunciado original do teste está preservado na íntegra [mais abaixo](#teste-técnico--desenvolvedor-fullstack).

| | |
|---|---|
| **Começar** | [Como executar](#como-executar) · [Makefile](#os-alvos-do-makefile) · [Serviços](#serviços) · [Gerando volume](#gerando-volume-para-teste) · [Testes](#testes) · [Ponta a ponta](#testes-de-ponta-a-ponta) · [CI](#integração-contínua) |
| **Domínio** | [Perfis de acesso](#perfis-de-acesso) · [Modelagem](#modelagem) · [Cálculo de juros](#cálculo-de-juros) · [Autenticação](#autenticação) · [API](#documentação-da-api) |
| **Módulos** | [Clientes](#módulo-de-clientes) · [Importação CSV](#importação-por-csv) · [Cobranças](#módulo-de-cobranças) · [Relatório](#relatório-de-faturamento) |
| **Performance** | [Dashboard](#dashboard) · [Índices](#índices) · [Exportação CSV](#exportação-em-csv) · [Exportação PDF](#exportação-em-pdf) |
| **Decisões** | [Página pública](#página-pública) · [Fundação visual](#fundação-visual) · [Técnicas](#decisões-técnicas) · [Erro e carregamento](#estados-de-erro-e-carregamento) · [Produção](#melhorias-que-ficariam-para-produção) · [Uso de IA](#uso-de-inteligência-artificial) |

---

## Como executar

Pré-requisito único: **Docker com Compose v2**. Não é preciso ter PHP, Node ou
MySQL instalados.

```bash
git clone https://github.com/Santiann/billing-reports.git
cd billing-reports
git checkout joao-santian
make install
```

`make install` é o comando único: sobe os quatro serviços, **espera as
migrations do entrypoint terminarem** e cria o usuário de acesso. No fim ele
imprime as URLs e as credenciais.

Sem `make`, são dois comandos — e o segundo só funciona depois que as
migrations terminam, o que na primeira subida demora:

```bash
docker compose up -d
docker compose exec php php artisan db:seed
```

Não há `.env` para copiar nem `composer install` para rodar à mão: o entrypoint
do backend resolve os dois (ver [Bootstrap automático](#bootstrap-automático-do-backend)).

| Perfil | E-mail | Senha |
|---|---|---|
| Administrador | `admin@billing.test` | `password` |
| Consulta | `consulta@billing.test` | `password` |

O segundo usuário existe para o perfil de consulta poder ser visto funcionando
(ver [Perfis de acesso](#perfis-de-acesso)).

### Os alvos do Makefile

`make` sem argumento lista tudo. Nenhum alvo esconde o `docker compose`: a
coluna da direita é o que cada um executa, para quem não tem `make` instalado
ou prefere digitar à mão.

| Alvo | Equivalente |
|---|---|
| `make install` | `docker compose up -d` + espera + `db:seed` |
| `make up` | `docker compose up -d` |
| `make down` | `docker compose down` |
| `make logs` | `docker compose logs -f` |
| `make shell` | `docker compose exec php sh` |
| `make test` | `docker compose exec php php artisan test` |
| `make coverage` | `docker compose exec php php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text` |
| `make seed` | `docker compose exec php php artisan db:seed` |
| `make seed-volume` | `docker compose exec php php artisan db:seed --class=BillingVolumeSeeder` |
| `make fresh` | `docker compose exec php php artisan migrate:fresh --seed` |
| `make lint` | `pint --test` no backend, `next typegen`, `tsc --noEmit` e `eslint` no frontend |
| `make e2e` | `docker compose --profile e2e run --rm e2e` — Playwright contra a stack em execução |
| `make explain` | `docker compose exec php php artisan report:explain` — passe opções com `ARGS="--analyze"` |

Duas decisões que o arquivo registra:

**`install` usa `up -d`, não `up -d --build`.** Numa máquina limpa não há
imagem e o compose constrói de qualquer jeito, então o `--build` não
acrescenta nada além de um caminho a mais para dar errado: ele precisa
resolver `docker/dockerfile:1` no registry, o que passa pelo helper de
credenciais do Docker. No WSL com Docker Desktop esse helper é um `.exe`, e
quando o interop não está disponível ele falha com `exec format error` — com a
stack inteira funcionando. Para reconstruir de propósito depois de mexer num
Dockerfile: `docker compose up -d --build`.

**`install` espera as migrations antes de semear.** `up -d` devolve o controle
quando os containers sobem, mas o entrypoint do php roda as migrations depois
disso. Semear sem esperar falha com *table users doesn't exist* — e falha
exatamente na primeira subida, que é a única em que `make install` importa.
Medido aqui: a espera durou 35 segundos com o datadir do MySQL já criado.

**O `lint` precisou de um `pint.json`.** O preset `laravel` do Pint remove os
parênteses de `new` sem argumento — `new InterestCalculator` em vez de
`new InterestCalculator()` — e o projeto inteiro usa a forma com parênteses.
Duas saídas eram possíveis: reescrever o código para o preset, ou registrar a
escolha. Optei pela segunda, porque `new X()` é a forma que o PHP 8.4 passou a
aceitar encadeada (`new X()->metodo()`) e a que deixa a chamada parecida com
qualquer outra.

A regra não pode ser simplesmente ligada, e essa é a parte não óbvia:
`"new_with_parentheses": true` também exigiria parênteses em **classe
anônima**, e reescreveria as quatro migrations, que usam
`return new class extends Migration`. A configuração separa os dois casos:

```json
{
    "preset": "laravel",
    "rules": {
        "new_with_parentheses": { "named_class": true, "anonymous_class": false }
    }
}
```

Com isso `make lint` passa limpo. As outras três divergências que o Pint
apontou eram defeito de verdade e foram corrigidas, não silenciadas: três
arquivos de teste usavam classe totalmente qualificada no meio do código
(`\App\Models\Billing::factory()`) em vez de `use` no topo.

Com os quatro serviços de pé:

| | URL |
|---|---|
| API (Laravel, via nginx) | <http://localhost:8000> |
| Aplicação (Next.js) | <http://localhost:3000> |

Conferindo:

```bash
curl -s -o /dev/null -w 'laravel: %{http_code}\n' http://localhost:8000
curl -s -o /dev/null -w 'next:    %{http_code}\n' http://localhost:3000
docker compose ps
```

Derrubar preservando o banco:

```bash
docker compose down
```

Derrubar apagando o banco (volume nomeado `mysql_data`):

```bash
docker compose down -v
```

### O primeiro boot é lento, e isso é esperado

O MySQL 8 cria o datadir do zero na primeira subida, e em disco lento — WSL2 e
virtiofs, principalmente — isso é bem mais demorado do que se espera. Medição
real nesta máquina: **10 minutos e 20 segundos** entre `Initializing database
files` e `ready for connections` na porta 3306. Nesse intervalo o backend fica
parado esperando o healthcheck, e é o comportamento correto.

Por isso o healthcheck tem `start_period` de 900s. O primeiro valor que tentei,
600s, falhou por 20 segundos e derrubou a subida inteira com
`dependency failed to start: container mysql is unhealthy`.

Falhas dentro do `start_period` não consomem retries, então a janela larga não
custa nada nos boots seguintes: com o volume já populado, o healthcheck passa
na primeira sonda e a stack sobe em segundos.

A sonda é por TCP (`mysqladmin ping -h 127.0.0.1`) de propósito. Durante o init
o MySQL levanta um servidor temporário com `port: 0`, sem rede — uma sonda por
socket Unix reportaria "pronto" enquanto o banco ainda não aceita conexão
nenhuma, e o backend tentaria migrar contra um servidor sem o usuário da
aplicação.

Se quiser acompanhar: `docker compose logs -f mysql`.

---

## Serviços

```
docker compose ps
```

| Serviço | Imagem / build | Porta no host | Papel |
|---|---|---|---|
| `mysql` | `mysql:8.0` | — | Banco. Volume nomeado `mysql_data`. |
| `php` | `backend/Dockerfile` | — | PHP-FPM 8.3. Fala FastCGI na 9000. |
| `backend` | `nginx:1.27-alpine` | **8000** | Serve o Laravel por HTTP. |
| `frontend` | `frontend/Dockerfile` (target `dev`) | **3000** | Next.js em modo desenvolvimento. |

### Por que o nginx se chama `backend` e o PHP se chama `php`

Essa é a decisão menos óbvia do arquivo, então ela fica explícita.

Dentro do Compose o Next tem duas origens de API e elas não são
intercambiáveis:

| Contexto | Base | Variável |
|---|---|---|
| Server Components, Route Handlers, middleware | `http://backend` | `API_URL_INTERNAL` |
| Código executando no browser | `http://localhost:8000` | `NEXT_PUBLIC_API_URL` |

O nome do serviço que atende `API_URL_INTERNAL` precisa ser o de **quem responde
HTTP**. PHP-FPM não responde HTTP — ele fala FastCGI na porta 9000. Se o
serviço php-fpm fosse chamado de `backend`, todo `fetch('http://backend/...')`
de Server Component falharia, e falharia **só dentro do Docker**, que é a pior
categoria de bug deste projeto.

Chamar o nginx de `backend` mantém `API_URL_INTERNAL=http://backend`
literalmente verdadeiro. Quem faz o trabalho de PHP se chama `php`.

### Bootstrap automático do backend

`vendor/` e `backend/.env` são gitignored, ou seja: num clone novo **nenhum dos
dois existe**. Sem tratamento, `docker compose up -d` entregaria um Laravel
quebrado e exigiria passos manuais — exatamente o que o teste proíbe.

O `backend/docker/entrypoint.sh` cobre isso a cada subida, de forma idempotente:

1. Se `vendor/autoload.php` não existe, roda `composer install`. O bind mount do
   Compose cobre o `/var/www/html` da imagem, então o vendor do build fica
   invisível de qualquer jeito — instalar no entrypoint é o que dispensa ter
   composer na máquina de quem avalia.
2. Se `.env` não existe, copia de `.env.example`.
3. Se `APP_KEY` está vazia, roda `php artisan key:generate`.
4. Garante `storage/` e `bootstrap/cache/` com dono `www-data`.
5. Roda `php artisan migrate --force`, com até 10 tentativas.

O passo 5 tem retentativa porque o healthcheck do MySQL pode passar durante a
fase de init, antes de o usuário da aplicação existir — `depends_on:
service_healthy` reduz a janela, não a elimina. E a migration é necessária já
nesta etapa: o Laravel está configurado com `SESSION_DRIVER=database` e
`CACHE_STORE=database`, então sem as tabelas qualquer rota web responde 500.

### Permissões de arquivo

O `backend/Dockerfile` aceita `UID`/`GID` como build args (default `1000`) e
alinha o `www-data` a esses valores. É isso que permite ao Laravel escrever em
`storage/` através do bind mount sem recorrer a `chmod 777`. Em Docker Desktop
(macOS/Windows) o valor é irrelevante — o mount já traduz o dono. Em Linux com
UID diferente de 1000:

```bash
UID=$(id -u) GID=$(id -g) docker compose up -d --build
```

---

## Autenticação

Sanctum com token bearer no backend; no browser, **o token nunca aparece**.

```
browser                Next (servidor)              Laravel
   |  POST /api/auth/login   |                          |
   |------------------------>|  POST /api/auth/login    |
   |                         |------------------------->|
   |                         |<-- { token, user } ------|
   |<-- { user } ------------|                          |
   |    Set-Cookie: httpOnly |                          |
```

O `POST /api/auth/login` que o formulário chama é um **Route Handler do
Next**, não o Laravel. Ele recebe o token Sanctum, grava num cookie `httpOnly`
e devolve só o usuário. Consequências:

- Não há token em `localStorage`, então um XSS não tem o que roubar.
- O browser não consegue chamar a API do Laravel diretamente — não tem
  credencial. Quem anexa o `Authorization: Bearer` é sempre o servidor.
- Por isso as exportações de PDF e CSV também vão passar por Route Handler,
  nas etapas de relatório.

`middleware.ts` protege as rotas pela **presença** do cookie. Ele não valida o
token: validar é trabalho do Laravel, em toda requisição de dado. Um cookie
forjado não abre nada — a API responde 401 e o Server Component redireciona.

### O cookie que sobrevive ao token

Se o cookie existe mas o token não vale mais (revogado, expirado, forjado), o
caminho ingênuo entra em loop: o middleware vê cookie e manda para `/`, o
Server Component recebe 401 e manda para `/login`, o middleware vê cookie de
novo e manda para `/`.

Por isso existe `GET /api/auth/expire`: ele apaga o cookie e só então
redireciona para o login. Está fora do `matcher` do middleware, então responde
mesmo com sessão aparente. Verificado: a cadeia termina em dois saltos.

### Códigos de resposta

Credencial errada responde **401**, não 422. O payload é válido; o que falhou
foi autenticar. E-mail inexistente e senha errada devolvem a **mesma**
mensagem, para a resposta não revelar quais e-mails existem. Payload malformado
(campo faltando) é que responde 422, com os erros por campo.

Tentativas demais respondem **429**, com `Retry-After` — ver
[rate limit no login](#rate-limit-no-login).

---

## Documentação da API

A especificação vive em [`backend/resources/openapi.yaml`](backend/resources/openapi.yaml)
— **OpenAPI 3.1**, escrita à mão, cobrindo os 15 endpoints com parâmetros,
respostas, exemplos e os códigos de erro.

### O que impede a spec de apodrecer

Documentação de API escrita à mão apodrece em silêncio: alguém acrescenta um
endpoint, esquece do arquivo, e a partir dali a spec descreve um sistema que não
existe mais. Ninguém percebe, porque nada quebra.

`OpenApiSpecTest` remove esse silêncio comparando a spec com o roteador do
Laravel **nas duas direções**:

| Situação | Resultado |
|---|---|
| Rota registrada sem entrada na spec | falha — documentação incompleta |
| Entrada na spec sem rota registrada | falha — documentação fantasma |

As rotas que ficam de fora de propósito — `/up`, `sanctum/csrf-cookie`, o
servidor de arquivos do disco público e a raiz — estão numa lista explícita, com
o motivo de cada uma. A lista existe justamente para que uma rota nova não
escape por omissão: se aparecer uma que não está nem na spec nem na lista, o
teste falha e alguém precisa decidir.

O teste ainda cobra quatro coisas que separam documentação de índice:

- toda operação tem `summary`, `tags` e respostas declaradas;
- toda operação autenticada documenta o **401** — é a resposta mais provável de
  quem experimenta a API pela primeira vez, e a que mais confunde sem
  explicação;
- toda resposta de sucesso em JSON traz **exemplo**;
- o **422 do teto do PDF** está documentado, e o limite do exemplo é comparado
  com `config/reports.php` — se o teto mudar e a spec não, o teste acusa.

### A raiz do backend é a documentação

```bash
curl localhost:8000          # a documentação inteira, em HTML
curl localhost:8000/openapi.yaml   # a spec crua, para importar
```

`http://localhost:8000` deixou de ser a welcome do Laravel. Quem abre esse
endereço está procurando a API, e entregar a página de boas-vindas do framework
desperdiça a única URL que a pessoa já sabe de cor.

**A página é montada no servidor, e essa foi a decisão que custou mais.** O
caminho fácil era Redoc, Scalar ou Stoplight Elements: uma linha de HTML, uma
tag de `<script>` de CDN, e um resultado bonito de graça. Todos os três montam
a página no browser — `curl localhost:8000` devolveria `<div id="app">` e mais
nada.

Documentação que só existe depois do JavaScript não se lê pelo terminal, não se
indexa, não abre sem internet e não sobrevive a uma CDN fora do ar. O critério
"`curl` responde a documentação" não é capricho: é o que separa documentação de
página de documentação.

O custo da escolha é um controller de 200 linhas e uma view — resolver `$ref`,
fundir os parâmetros declarados no path com os da operação, formatar exemplo. O
que se ganha:

| | Renderizador de CDN | Esta página |
|---|---|---|
| `curl` devolve a documentação | não | **sim** |
| Funciona sem internet | não | **sim** |
| Dependência de terceiro em runtime | sim | **nenhuma** |
| JavaScript | obrigatório | **zero** |

O visual é de especificação impressa — papel, tinta, fio de régua e numeração de
seção (`3.6 Registra o pagamento e congela os juros`). Sem fonte externa, pelo
mesmo motivo de não ter CDN: a página abre offline com as famílias que a máquina
já tem. Há folha de estilo de impressão, porque um documento que se chama
especificação deveria sair bem no papel.

O parse do YAML não é cacheado de propósito: leva poucos milissegundos, e editar
a spec e recarregar mostra o resultado na hora — que é o que se quer de um
arquivo mantido à mão.

### Decisões

**YAML e não JSON**, com `symfony/yaml` para o teste conseguir ler. JSON não
precisaria de dependência nenhuma, mas a spec é um documento que alguém vai
abrir e ler: YAML aceita comentário, e o arquivo começa explicando por que ele é
verificado por teste. A dependência é pequena, é da Symfony e já convive com o
Laravel.

**Escrita à mão e não gerada do código.** Um gerador por anotação (Scramble,
L5-Swagger) produziria a spec a partir dos controllers, e ela nunca divergiria —
mas também nunca diria mais do que o código já diz. A parte útil desta
documentação é a que o código não tem: por que cobrança paga é imutável, por que
o dinheiro trafega como string, por que o PDF tem teto e o CSV não. O teste
cobre a divergência; o texto cobre o resto.

**Exemplos tirados de chamadas reais.** Todo exemplo da spec saiu de uma
resposta de verdade da API, com os valores de juros conferidos contra o
`InterestCalculator` — R$ 1.500,00 a 2% ao mês com 30 dias de atraso dá
`1500 * 1,02 = 1530,00`. Exemplo inventado é a primeira coisa que fica errada.

A spec foi validada com `npx @redocly/cli lint`: **válida**, com dois avisos
aceitos de propósito — não declarar licença, e apontar o servidor para
`localhost`, que neste projeto é o servidor certo.

---

## Modelagem

### `customers`

| Coluna | Tipo | Nota |
|---|---|---|
| `name` | varchar | indexado — a listagem ordena por nome |
| `document` | varchar(14) **unique** | CPF/CNPJ só com dígitos, sem máscara |
| `email` | varchar | |
| `status` | varchar(20) | `active` / `inactive` |

### `billings`

| Coluna | Tipo | Nota |
|---|---|---|
| `customer_id` | FK **restrict** | cobrança é registro financeiro; apagar cliente não evapora histórico |
| `original_amount` | decimal(12,2) | |
| `monthly_interest_rate` | decimal(6,4) | fração: `0.0200` = 2% ao mês |
| `issue_date` · `due_date` · `payment_date` | date | as três datas que podem definir o período do relatório |
| `status` | varchar(20) | `pending` / `paid` |
| `paid_amount` · `paid_interest_amount` | decimal(12,2) nulos | congelamento no ato do pagamento |

### "Vencida" não é um status armazenado

O enum gravado tem dois valores: `pending` e `paid`. Vencida é uma **condição
derivável** — `status = 'pending' AND due_date < ?`, com a data de referência
descendo do PHP e não vindo de `CURDATE()` (o porquê está em
[armadilhas do cálculo](#três-armadilhas-que-o-desenho-precisou-resolver)).

Armazenar "overdue" exigiria um job diário virando linhas de pendente para
vencida. Entre duas execuções desse job a coluna estaria mentindo, e num
relatório financeiro isso é pior do que o custo de derivar. A derivação é
sempre correta, roda em SQL e é indexável pelo par `(status, due_date)`.

### Por que DECIMAL e não FLOAT

Dinheiro em ponto flutuante acumula erro de arredondamento. O relatório soma
juros sobre o conjunto filtrado inteiro — milhões de linhas — e o erro cresce
com o número de parcelas somadas. Os casts do Eloquent são `decimal`, que
devolve **string**, não float: é proposital, e há teste afirmando que
`1234.56` volta do banco como `'1234.56'`.

### As colunas de congelamento

`paid_amount` e `paid_interest_amount` são gravadas no momento do pagamento e
nunca recalculadas. Sem elas, uma cobrança paga com atraso mudaria de valor a
cada dia que passasse, porque o cálculo de juros é função da data atual.

---

## Módulo de clientes

| Método | Rota | |
|---|---|---|
| `GET` | `/api/customers` | lista paginada, com busca, filtro e ordenação |
| `POST` | `/api/customers` | cadastro |
| `GET` | `/api/customers/{id}` | visualização |
| `PUT` | `/api/customers/{id}` | edição |

Telas correspondentes em `/clientes`, `/clientes/novo`, `/clientes/{id}` e
`/clientes/{id}/editar`.

**Filtro, ordenação e paginação acontecem no banco.** Em nenhum ponto o
conjunto é carregado para ser recortado em memória — o Next repassa os
parâmetros e recebe já a página.

### Duas guardas que a API precisa ter

`sort` é validado contra allowlist (`name`, `document`, `email`,
`created_at`). Ele entra no `ORDER BY`, e aceitar o valor cru seria injeção.
Valor fora da lista responde 422.

`per_page` tem teto de 100. Sem isso, `?per_page=999999` derruba a API com uma
única requisição.

Há teste para as duas.

### Busca

Um campo só, que casa contra nome, e-mail e documento. O documento só entra na
cláusula se o termo tiver dígitos: sem essa guarda, buscar por "Aurora" viraria
`document LIKE '%'` e traria a tabela inteira. O casamento do documento é por
prefixo, que usa o índice unique; nome e e-mail usam `LIKE %termo%`, aceitável
porque a tabela de clientes é pequena — a de cobranças, que não é, tem
tratamento próprio na etapa do relatório.

### Documento sem máscara

Chega da tela como `123.456.789-01` e é gravado como `12345678901`. Guardar o
que foi digitado faria a busca depender do formato escolhido por quem cadastrou.
A normalização é no `prepareForValidation()` do FormRequest, antes da regra de
unicidade rodar — senão o mesmo CPF com e sem pontuação passaria como dois
clientes distintos.

### Server Actions para as mutações

Cadastro e edição usam **Server Actions**, não Route Handlers. O motivo é o
mesmo do login: o browser não tem o token, então quem fala com o Laravel é o
servidor. A Action lê o cookie httpOnly, anexa o `Bearer`, e devolve os erros
de validação campo a campo para o formulário exibir — em vez de virarem uma
mensagem genérica.

Route Handler continua sendo a escolha onde o browser precisa de uma URL para
navegar ou baixar: login, logout e, nas etapas de relatório, as exportações.

Os filtros vivem na **URL**, não em estado de componente: a página fica
compartilhável, sobrevive ao refresh, e o Server Component monta a consulta já
filtrada. A confirmação de sucesso também vem por parâmetro de URL, porque
precisa sobreviver ao redirect que a Action faz depois de salvar.

### Mensagens de validação em português

`lang/pt_BR/validation.php` cobre as regras efetivamente usadas, com
`attributes` traduzindo os nomes de campo. Sem isso a tela misturaria
"The name field is required." com as mensagens customizadas em português.
`APP_LOCALE=pt_BR`.

---

## Módulo de cobranças

| Método | Rota | |
|---|---|---|
| `GET` | `/api/billings` | lista paginada, com filtro por cliente, status e descrição |
| `POST` | `/api/billings` | cadastro |
| `GET` | `/api/billings/{id}` | visualização |
| `PUT` | `/api/billings/{id}` | edição |

Telas em `/cobrancas`, `/cobrancas/nova`, `/cobrancas/{id}` e
`/cobrancas/{id}/editar`.

### Status e pagamento não são campos de formulário

`status`, `payment_date`, `paid_amount` e `paid_interest_amount` **não estão**
nas regras do FormRequest. Só o que passa por `rules()` chega em `validated()`,
então enviá-los não tem efeito — há teste postando `status: paid` e afirmando
que a cobrança nasce pendente.

O motivo é integridade: aceitar `status = paid` no cadastro criaria uma
cobrança paga **sem os valores congelados**, e esses valores não são
recuperáveis depois, porque o cálculo é função da data em que o pagamento
ocorreu. A transição para paga pertence ao registro de pagamento.

Pela mesma razão, **cobrança paga não pode ser editada**: alterar valor ou taxa
invalidaria `paid_amount` e `paid_interest_amount`. A API responde 422, e a
tela de edição redireciona antes de servir um formulário que só falharia no
envio.

### N+1

A listagem exibe o nome do cliente, e sem eager loading isso seria um `SELECT`
por linha na serialização. O controller usa `with('customer')`, e o
`BillingResource` usa `whenLoaded` — assim a chave some quando a relação não
foi carregada, em vez de disparar consulta durante a serialização.

Há teste que **conta as consultas**: cria dez cobranças de dez clientes
distintos e afirma no máximo três queries (count da paginação, select das
cobranças, select dos clientes). Sem eager loading seriam treze.

### Seletor de cliente

A base de teste tem cinco mil clientes, então um `<select>` com todos está
fora. O formulário usa um combobox que busca conforme se digita, com debounce
de 300 ms, através de um Route Handler — o browser não tem o token, então quem
consulta a API é o servidor. O id selecionado viaja num input escondido, de
modo que o formulário continua sendo um form comum e a Server Action não
precisa saber que existe um combobox.

### Ordenação default por `id desc`

É a chave primária: ordenar por ela não custa filesort. As demais colunas de
ordenação (`due_date`, `issue_date`, `original_amount`) ainda não têm índice
nesta etapa — eles entram em `feat: add report indexes`, cada um documentado
junto da consulta que serve.

A busca por descrição usa `LIKE '%termo%'`, que não é indexável por ter
curinga à esquerda. Aceitável para a tela de CRUD; a alternativa de produção é
índice FULLTEXT, registrado na lista de melhorias.

### Observações de performance, ainda sem índices

Medições preliminares contra a base de milhões, **antes** da etapa de índices.
Ficam registradas porque são elas que justificam o que vem lá:

Base de **2.000.000 de cobranças**, banco sem escrita concorrente:

| Consulta | Tempo |
|---|---|
| `SELECT COUNT(*) FROM billings` | **26,8s** |
| `ORDER BY due_date DESC LIMIT 15` (sem índice) | **3,5s** |
| `COUNT(*) WHERE status = 'paid'` | 1,2s |
| `ORDER BY id DESC LIMIT 15` (chave primária) | 0,4s |

| Ambiente | |
|---|---|
| Tabela `billings` | 149 MB |
| `innodb_buffer_pool_size` | 128 MB (default) |

Sob carga de escrita concorrente os números pioram muito: a listagem chegou a
5,3 minutos e recebeu 504 do nginx, e o `COUNT(*)` passou de 120s.

Dois problemas distintos aparecem aqui.

O primeiro é o `COUNT(*)` que o `paginate()` do Laravel dispara **a cada
requisição** para calcular `last_page`. Ele não depende do `LIMIT`: percorre o
conjunto filtrado inteiro, toda vez.

O segundo é que a tabela não cabe no buffer pool. Com 149 MB de dados e 128 MB
de pool, cada varredura completa vai ao disco — e foi isso que derrubou a taxa
de inserção do seeder de ~1.900 para ~150 linhas por segundo na segunda metade
da carga.

Ambos são endereçados em `feat: add report indexes`, com medição antes e
depois.

---

## Importação por CSV

`/clientes/importar` recebe um arquivo, mostra o que vai acontecer e só grava
depois de confirmado.

**Importação parcial é o comportamento esperado, não uma falha.** Linha com erro
não impede as outras de entrar: as válidas são importadas, e as recusadas voltam
nomeadas com o número da linha **do arquivo** — contando o cabeçalho, que é como
o usuário a encontra ao abrir a planilha — e o motivo. Abortar tudo por causa de
um e-mail errado na linha 47 obrigaria a corrigir e reenviar o arquivo inteiro.

### O arquivo não fica guardado entre a prévia e a confirmação

O caminho comum seria gravar o upload num diretório temporário, devolver um
identificador e usá-lo no confirmar. Isso traz junto expiração, faxina de
arquivo abandonado e um estado a mais para errar.

Aqui a confirmação **reenvia o mesmo arquivo**, que ainda está no input do
browser. O custo é um upload a mais — trivial para um CSV de clientes — e o
relatório da confirmação sai do mesmo código da prévia, então o que o usuário
viu é o que aconteceu.

Isso custou um defeito que só apareceu na tela: um `<form action={fn}>` é
**resetado pelo React** depois que a action termina, e o campo de arquivo voltava
a "nenhum arquivo selecionado" — a prévia apagava justamente o que o passo
seguinte precisava, e o botão de importar não gravava nada. A action passou a ser
chamada por `onSubmit` dentro de uma transição, que não toca no formulário.

### Streaming, e por quê

O arquivo é lido linha a linha com `fgetcsv` sobre um gerador. Nunca
`file_get_contents` nem `file()`: um CSV de cem mil clientes não pode existir de
uma vez na memória do processo. Há teste afirmando que importar 5.000 linhas não
faz o pico de memória crescer mais que 32 MB.

A gravação vai em **lotes de 500**, e cada lote confere os documentos contra o
banco numa consulta só. Uma consulta por linha transformaria dez mil clientes em
dez mil consultas; um insert em lote sem conferir estouraria a unique do banco e
derrubaria as 499 linhas boas junto com a repetida.

### Conveniências que vêm de quem exporta planilha

| | |
|---|---|
| Separador | detectado — `;` do Excel em português ou `,` |
| Cabeçalho | aceita apelidos: `nome`/`name`, `documento`/`cpf`/`cnpj` |
| Documento | pode vir com máscara; é gravado só com dígitos |
| Status | `ativo`/`active`, e vazio assume ativo |
| BOM do Excel | removido antes de comparar o cabeçalho |

Exigir um formato exato transformaria "o arquivo não funciona" num problema de
suporte.

**Arquivo sem as colunas obrigatórias é recusado inteiro**, com 422 no campo do
upload — e a mensagem diz o nome que o usuário precisa digitar (`documento`),
não o nome interno do campo (`document`). Não há o que importar parcialmente
quando nem dá para saber o que é cada coluna.

### Cobranças: duas regras a mais

`/cobrancas/importar` usa o mesmo leitor e o mesmo formulário, com duas regras
próprias.

**O cliente é resolvido pelo documento**, não por id. O arquivo vem de fora e
não conhece o id interno; documento é a identidade de negócio que as duas pontas
têm. A resolução acontece **por lote**: uma consulta traz os clientes dos 500
documentos de uma vez, e há teste afirmando que mil cobranças espalhadas por cem
clientes não passam de 20 consultas — sem o lote seriam mil.

**A cobrança nasce pendente**, como a cadastrada pela tela. Coluna de status ou
de valor pago no arquivo é **ignorada**, não aceita: aceitar `paid` criaria
cobrança paga sem os valores congelados, que é o mesmo motivo pelo qual o
formulário de cadastro não tem esses campos. Existe teste enviando
`status;valor_pago` no arquivo e afirmando que a cobrança entra pendente.

Formatos que vêm de planilha, todos aceitos:

| No arquivo | No banco |
|---|---|
| `1.234,56` ou `1234.56` | `1234.56` |
| `09/08/2026` ou `2026-08-09` | `2026-08-09` |
| `0,035` | `0.0350` |
| taxa ausente | `0` — cobrança sem juros é legítima |

A conversão de data usa `DateTimeImmutable` e não `CarbonImmutable`, e isso é
deliberado: o Carbon **lança exceção** quando o valor não casa com o formato, em
vez de devolver `false` como o nativo. Aqui a tentativa que falha é o caso
normal — são quatro formatos testados em sequência — e usar exceção para fluxo
esperado custa caro e lê pior. As duas convertem `32/13/2026` rolando para o mês
seguinte, então a data é formatada de volta e comparada com a original; sem isso,
data inválida viraria cobrança com vencimento errado em vez de erro na linha.

Ao contrário de cliente, **linhas idênticas geram duas cobranças**: cobrança não
tem chave natural, e duas mensalidades do mesmo cliente com o mesmo vencimento
são duas cobranças de verdade.

---

## Cálculo de juros

Juros **compostos**:

```
valor_atualizado = valor_original x (1 + taxa_mensal) ^ (dias_atraso / 30)
```

Só acumula quem está **vencida e não paga**. Cobrança em dia tem juros zero;
cobrança paga lê os valores congelados.

### A regra tem uma fonte só, com duas faces

`App\Domain\Billing\InterestCalculator` existe porque o relatório precisa
**ordenar por valor atualizado** e **somar juros sobre o conjunto filtrado
inteiro**. Se o cálculo vivesse só em PHP, qualquer uma dessas operações
obrigaria a carregar o resultado inteiro em memória.

| Face | Onde é usada |
|---|---|
| `updatedAmountSql()` / `interestAmountSql()` | `selectRaw` na listagem e nas agregações |
| `for(Billing)` | exibição de uma cobrança isolada |

Duas implementações da mesma regra divergem em silêncio. Por isso
`InterestCalculatorTest` roda **a mesma matriz de 12 casos pelas duas faces** e
afirma igualdade até o centavo — em dia, vencida por 1, 30, 281 e 400 dias,
taxa zero, taxa alta, centavos quebrados, paga em dia e paga em atraso.

### Três armadilhas que o desenho precisou resolver

**`travelTo()` não move o relógio do MySQL.** Se a face SQL usasse `CURDATE()`,
o teste de consistência compararia PHP em tempo congelado contra SQL em tempo
real e nunca fecharia. A data de referência desce do PHP como literal — gerada
a partir de um Carbon, nunca vinda da requisição. É também o que permite
calcular juros *na data do pagamento*, que é o que o congelamento exige.

**Divisão em MySQL devolve DECIMAL, não double.** `400 / 30` vira `13.3333`,
truncado em quatro casas, enquanto em PHP é `13.333333…`. Expoentes diferentes,
`POW` diferente, faces divergentes.

Isso não é teórico: um varrimento de 900 dias x 6 taxas x 3 valores encontrou
**78 combinações** em que a truncagem muda o centavo. Uma delas está na matriz
do teste — R$ 987.654,31 a 3,5% com 281 dias de atraso, onde DECIMAL dá
`1363158.13` e double dá `1363158.14`. O `/ 30e0` do `compoundSql()` força a
divisão a virar double, e removê-lo faz esse caso falhar.

**PHP e MySQL desempatam o meio centavo em direções opostas.** Quando a conta
cai exatamente sobre o meio centavo, `round()` do PHP arredonda meio para longe
do zero e `ROUND()` do MySQL sobre `DOUBLE` arredonda meio para par:

```
4224,10 a 5% ao mês, 30 dias de atraso  ->  4435,305
PHP   round(, 2)   4435,31     meio para longe do zero
MySQL ROUND(, 2)   4435,30     meio para par, porque o argumento é DOUBLE
```

Em cobrança paga isso não aparece — as duas faces leem a coluna congelada. Em
cobrança **pendente vencida** aparece: a tela de detalhe usa a face PHP e o
relatório usa a face SQL, e as duas mostrariam valores diferentes para a mesma
cobrança. É exatamente a inconsistência entre tela e relatório que o teste
proíbe.

A frequência é baixa e foi medida, não estimada: **uma ocorrência em 200.000**
combinações varridas, e uma na base de 2.000.000 (13.654 pagas em atraso). Só
acontece quando o produto é exato o bastante para cair no empate, o que na
prática quer dizer atraso múltiplo de 30 dias.

A correção são **seis casas de guarda**: arredondar primeiro em seis casas e só
então em duas. Na face PHP, `round(round($v, 6), 2)`; na face SQL, um `CAST`
para `DECIMAL(20,6)` antes do `ROUND`. Nos dois motores o arredondamento final
passa a operar sobre um decimal exato em vez de sobre o double, e o empate
desempata para o mesmo lado. A varredura de 200.000 combinações que achava uma
divergência passou a achar zero.

O ganho secundário justifica sozinho: as duas `pow()` rodam em containers
diferentes e não compartilham a mesma libm, então uma diferença de 1 ULP entre
elas é possível. As casas de guarda absorvem isso.

### Congelamento no pagamento

`App\Domain\Billing\RegisterPayment` calcula os juros **na data do
pagamento**, não em hoje: pagamento retroativo produz o valor daquele dia. A
partir daí a cobrança para de acumular — o `InterestCalculator` devolve as
colunas gravadas em vez de recalcular, nas duas faces.

O valor efetivamente recebido pode diferir do calculado (acordo, desconto);
quando não informado, assume-se o valor atualizado. Os juros calculados ficam
registrados de qualquer forma.

A factory usa **o mesmo serviço** nos states `paid()` e `paidLate()`. Escrever
os valores congelados à mão na factory faria dela uma segunda implementação da
regra, e os testes passariam a validar a cópia em vez do original.

---

## Relatório de faturamento

`GET /api/reports/billings`, tela em `/relatorio`.

| Filtro | Valores |
|---|---|
| `date_field` | `issue_date` · `due_date` · `payment_date` |
| `start_date` / `end_date` | o período, sobre a data escolhida acima |
| `customer_id` | |
| `status` | `pending` · `paid` · **`overdue`** |
| `sort` | as cinco colunas, incluindo `updated_amount` |

`overdue` não é status gravado: é a condição derivada `pendente + vencimento no
passado`, e vem da mesma classe que calcula os juros — a regra tem uma fonte
só, vista de dois ângulos.

### Totalizadores vêm de consulta separada

Quantidade, valor original, juros, valor atualizado, recebido e pendente saem
de **uma consulta de agregação sobre o conjunto filtrado inteiro**, nunca da
soma da página exibida. Na página 3 de um relatório de mil cobranças, somar a
página daria um número sem significado.

O teste monta 25 cobranças numa página de 10 e afirma que o total é 25, não 10.

`rows()` e `totals()` partem do **mesmo objeto de filtros** — é isso que
garante que o rodapé fale do mesmo conjunto que as linhas, e é o que as
exportações vão reusar para produzir arquivo idêntico ao que está na tela.

### Ordenar por valor atualizado

É a razão de o cálculo existir em SQL. Com ele apenas em PHP, ordenar por valor
atualizado obrigaria a carregar o conjunto inteiro em memória — que é o que o
teste proíbe. Há teste com uma cobrança de valor original menor porém muito
mais atrasada, afirmando que o valor atualizado inverte a ordem.

A paginação tem desempate por `id`: sem ele, duas páginas podem repetir ou
pular linhas quando há empate na coluna ordenada.

### `whereDate()` não é usado

Envolver a coluna em `DATE()` impede o MySQL de usar o índice, e o relatório é
exatamente onde isso não pode acontecer. As colunas já são do tipo `DATE`, e a
comparação é direta.

### Medição contra 2.000.000 de cobranças, antes dos índices

| Consulta | Tempo |
|---|---|
| 1 mês por vencimento (56.680 cobranças) | 3,8s |
| 1 mês ordenado por valor atualizado | 3,5s |
| 1 mês + filtro de vencidas | 4,2s |
| 1 ano | 4,3s |

Um mês custa o mesmo que um ano, e isso é o diagnóstico: o custo não vem do
tamanho do recorte, vem de varrer a tabela toda para encontrá-lo. O `EXPLAIN`
confirma:

```
EXPLAIN SELECT COUNT(*) FROM billings
WHERE due_date >= '2026-01-01' AND due_date <= '2026-01-31'

type: ALL      key: NULL      rows: 1989965
```

Com `customer_id` junto, a chave estrangeira entra e o plano muda para
`type: ref`, `rows: 418`. Ou seja: o filtro por cliente já tem índice, o filtro
por data não. É o que a seção seguinte resolve.

---

## Dashboard

A tela inicial mostra os indicadores do mês corrente e a série dos últimos doze
meses. Uma chamada, duas consultas de agregação, **nenhuma linha carregada para
o PHP somar** — sobre dois milhões de cobranças isso não seria lento, seria
impossível.

Medido contra a base cheia:

| | |
|---|---|
| `GET /api/dashboard` | 0,66s – 0,93s |
| **Página completa, com o SSR do Next** | **0,84s – 1,42s** |

O critério era 3 segundos. A primeira versão gastava 2,1s a 3,1s na página, e
três medições mudaram o desenho até chegar aqui.

### 1. O `GROUP BY` de um ano custa 5x mais que doze faixas de um mês

A forma óbvia da série é agrupar o ano inteiro por mês:

```sql
SELECT DATE_FORMAT(due_date, '%Y-%m'), SUM(original_amount) ...
WHERE due_date BETWEEN ? AND ? GROUP BY 1          -- 1,75s
```

O `EXPLAIN` explica: a função sobre a coluna impede o MySQL de agrupar na ordem
do índice, e ele monta tabela temporária com as 666.000 linhas do ano
(`Using temporary`). Sem o índice de cobertura ele nem tenta — escolhe varredura
completa dos 2.000.000, porque um terço da tabela em busca de linha sai mais
caro que ler tudo.

Doze faixas estreitas unidas por `UNION ALL` — uma por mês — são doze ranges
simples que o índice responde sem temporária:

```sql
SELECT ... WHERE due_date >= '2026-09-01' AND due_date < '2026-10-01'
UNION ALL ...                                      -- 0,33s
```

### 2. O índice de cobertura vale 25x, e as cinco colunas são todas usadas

`billings_dashboard_index` é `(due_date, status, monthly_interest_rate,
original_amount, paid_amount)`. Os sete índices do relatório apontam para a
linha; este **carrega os valores dentro de si**, e o `EXPLAIN` sai com
`Using index`.

| Consulta | Sem cobertura | Com este índice |
|---|---|---|
| Série de 12 meses | 8,25s | **0,31s** |
| Indicadores do mês | 0,72s | **0,07s** |

Uma versão estreita sem `status` e sem `monthly_interest_rate` foi medida e
descartada: economiza 21 MB e faz os indicadores voltarem de 0,07s para 0,72s,
porque o cálculo de juros passa a buscar a taxa linha por linha.

O custo está aceito e registrado: 79 MB e uma oitava árvore para manter a cada
insert, somando à penalidade de 4,8x que os sete índices do relatório já cobram
da carga.

### 3. O mesmo `POW` estava sendo calculado duas vezes por linha

`updatedAmountSql()` e `interestAmountSql()` carregam ambos o cálculo de juros
composto. Somados lado a lado, o MySQL executava o `POW` duas vezes em cada uma
das 55.000 linhas do mês.

O valor atualizado passou a ser calculado uma vez numa subconsulta, e os juros
saem dele por subtração — o que só vale porque a soma é sobre **pendente**, e em
cobrança pendente juros é exatamente valor atualizado menos original. Em
cobrança paga não valeria, e por isso ela entra com zero.

**0,87s → 0,25s**, com os seis números idênticos aos de antes.

### Os gráficos

Dois, dos mesmos doze números — o segundo não custa consulta nenhuma:

- **Faturado e recebido por mês**, coluna empilhada. A pergunta é parte-todo ao
  longo do tempo: a altura inteira é o faturado do mês e o corte mostra quanto
  virou dinheiro. Duas barras lado a lado responderiam "qual é maior", que não é
  a pergunta.
- **Taxa de recebimento**, linha. Mesmos números, outra leitura: eficiência de
  cobrança é o que não se enxerga quando faturamento e recebido crescem juntos.
  Eixo fixo de 0 a 100%, porque esticá-lo para o intervalo dos dados
  transformaria variação de dois pontos numa montanha.

São SVG montados no servidor, **sem JavaScript e sem biblioteca de gráfico**. Um
gráfico de doze números é uma figura, não uma aplicação: o destaque da coluna
sob o cursor é CSS, e o valor exato mora no `<title>` e na tabela que fica logo
abaixo, fechada num `<details>`.

**A paleta das séries foi validada por script, não no olho.** O verde e o âmbar
que as etiquetas usam foram reprovados como paleta de gráfico:

```
#1c6448 / #8a5d12   ΔE 14,6 normal · 6,7 protan   → REPROVADO
#147a58 / #c47d0c   ΔE 22,4 normal · 10,5 protan  → aprovado (claro)
#2d9d76 / #b07d20   ΔE 16,4 normal ·  9,9 deutan  → aprovado (escuro)
```

Etiqueta vem com texto ao lado e sobrevive a cores próximas; preenchimento de
gráfico não tem texto e precisa se distinguir sozinho. Os passos do tema escuro
não são o clareamento dos claros — a banda de luminosidade aceitável é outra
(L 0,48–0,67 contra 0,43–0,77).

Em tela estreita os gráficos **rolam na horizontal** em vez de encolher: o SVG
escalaria o rótulo junto, e um texto de 11px viraria 5px em 360px.

---

## Índices

Sete índices, cada um com a consulta que serve. O princípio é um só: **coluna
de igualdade antes da coluna de range**. O MySQL percorre um índice composto da
esquerda para a direita e para de usá-lo na primeira coluna de range — tudo
depois dela vira filtro pós-leitura, não busca.

| Índice | Consulta que serve |
|---|---|
| `(issue_date)` | `WHERE issue_date BETWEEN ? AND ?` |
| `(due_date)` | `WHERE due_date BETWEEN ? AND ?` |
| `(payment_date)` | `WHERE payment_date BETWEEN ? AND ?` |
| `(customer_id, issue_date)` | `WHERE customer_id = ? AND issue_date BETWEEN ? AND ?` |
| `(customer_id, due_date)` | `WHERE customer_id = ? AND due_date BETWEEN ? AND ?` |
| `(customer_id, payment_date)` | `WHERE customer_id = ? AND payment_date BETWEEN ? AND ?` |
| `(status, due_date)` | `WHERE status = ? AND due_date BETWEEN ? AND ?` e o filtro "vencida" |

As três datas precisam de índices separados porque o usuário escolhe qual
delas define o período, e o MySQL não usa um índice de `due_date` para filtrar
`issue_date`. As variantes com `customer_id` à frente existem porque a chave
estrangeira sozinha encontra as linhas do cliente e depois testa a data linha a
linha; com o par, a data também vira busca.

`ReportIndexTest` afirma que os sete existem **com as colunas na ordem certa** e
que são aplicáveis às consultas. Sem esse teste, remover um índice degradaria o
relatório em silêncio.

### Planos de execução, antes e depois

Os planos abaixo foram colhidos à mão, colando consultas no cliente do MySQL.
A partir da etapa 2 dá para reproduzi-los com um comando —
[`report:explain`](#o-plano-de-execução-como-ferramenta) —, que pega as
consultas do mesmo caminho que a API usa.

| Consulta | Antes | Depois |
|---|---|---|
| período por vencimento | `ALL` · sem chave · **1.989.965 linhas** | `range` · `due_date_index` · **107.694** |
| cliente + período | `ref` · FK · 418 | `range` · `customer_due_date_index` · **15** |
| vencidas | `ALL` | `range` · `status_due_date_index` · 994.525 |

### Tempo de resposta do relatório

| Recorte | Antes | Depois | |
|---|---|---|---|
| 1 mês + cliente | — | **0,24s** | |
| 1 mês + vencidas | 4,21s | **1,09s** | −74% |
| 1 mês, ordenado por valor atualizado | 3,46s | **1,84s** | −47% |
| 1 mês | 3,80s | **2,2s** | −42% |
| **1 ano** | **4,34s** | **6,2s** | **+43%** |

### O recorte de um ano piorou, e isso é esperado

Não é regressão a esconder: é o limiar de seletividade.

Um índice de range é lido em ordem e, para cada entrada, faz um acesso
aleatório à chave primária para buscar o resto da linha. Isso compensa enquanto
o recorte é pequeno. O período de um ano tem **518.170 linhas, 26% da tabela** —
acima do limiar, e meio milhão de acessos aleatórios custam mais do que uma
leitura sequencial da tabela inteira.

Medido isoladamente, sem o ruído da API:

| Agregação | Com índice | Sem índice (`IGNORE INDEX`) |
|---|---|---|
| 1 mês (5% da tabela) | **0,96s** | 1,47s |
| 1 ano (26% da tabela) | 2,43s | **2,32s** |

O outro fator é que os totalizadores são inerentemente O(n): somar juros exige
calcular `POW` para cada linha do conjunto filtrado. Nenhum índice evita isso —
índice acha as linhas, não dispensa a conta.

Das mitigações de produção listadas na etapa 1, o cache dos totalizadores por
combinação de filtros foi feito na etapa 2, [com medição e estratégia de
invalidação](#cache-dos-totalizadores). Tabela de agregados atualizada por
evento e particionamento por data continuam sendo as alternativas para quando o
cache não bastar — e ele não basta para a primeira consulta de cada recorte.

### Custo em disco

| | Antes | Depois |
|---|---|---|
| Dados | 107 MB | 177 MB |
| Índices | 43 MB | **322 MB** |

Os índices passaram a pesar quase o dobro dos dados. Com `innodb_buffer_pool_size`
no default de 128 MB, nada disso cabe em memória — dimensionar o pool para o
conjunto de trabalho é a primeira coisa a fazer em produção.

A migration levou **9min38s** para construir os sete índices sobre dois milhões
de linhas. Num ambiente limpo ela roda sobre tabela vazia e é instantânea; o
custo aparece depois, no seeder, que passa a manter sete índices a cada insert.

### O rollback tinha um defeito

O índice que a chave estrangeira usava era criado automaticamente pelo InnoDB.
Quando os compostos com `customer_id` à esquerda apareceram, **o InnoDB o
descartou por redundância** e passou a apoiar a constraint num deles.

Consequência: derrubar os compostos no `down()` falhava com

```
SQLSTATE[HY000] 1553 Cannot drop index
'billings_customer_payment_date_index': needed in a foreign key constraint
```

O `down()` recria o índice de `customer_id` **antes** de remover os compostos.
Verificado rodando o ciclo completo num banco descartável: depois do rollback
restam exatamente `PRIMARY` e `billings_customer_id_foreign`, o estado
pré-migration.

---

## Exportação em CSV

`GET /api/reports/billings/csv`, e no frontend o botão **Exportar CSV** da tela
do relatório.

O arquivo traz, nesta ordem: período selecionado e filtros aplicados no topo,
o cabeçalho das colunas, as linhas, e os totalizadores no rodapé.

### Streaming, e a prova de que é streaming

`lazy()` percorrendo o resultado em blocos de mil, escrevendo linha a linha em
`php://output` dentro de um `StreamedResponse`. O conjunto nunca existe inteiro
em memória.

Alegar isso é fácil; a medição contra a base de dois milhões:

| | |
|---|---|
| Recorte | 1 mês — 56.680 cobranças |
| **Tempo até o primeiro byte** | **0,88s** |
| Tempo total | 55,6s |
| Arquivo | 4,96 MB, 56.692 linhas |

O primeiro byte sai em menos de um segundo enquanto o arquivo inteiro leva
quase um minuto. Numa implementação que montasse o conjunto antes de responder,
os dois números seriam iguais — é essa distância que prova o streaming.

A memória confirma. Amostrada a cada 12 segundos durante uma exportação de três
meses (~170 mil linhas):

```
antes    70,9 MB
t+12s    80,3 MB      t+48s    80,3 MB
t+24s    80,7 MB      t+60s    80,1 MB
t+36s    80,3 MB      t+72s    80,6 MB
```

Plana. Acumular em array mostraria a curva subindo até o fim.

### Onde o tempo é gasto

Não é o `OFFSET` da paginação interna — medido, ele custa o mesmo em qualquer
profundidade, porque o índice de período já restringe o conjunto:

| | |
|---|---|
| `LIMIT 1000 OFFSET 0` | 0,34s |
| `LIMIT 1000 OFFSET 55000` | 0,31s |

Os 57 blocos somam cerca de 18s de banco. O restante é PHP: hidratar 56 mil
models Eloquent e instanciar Carbon para cada data. Dá cerca de mil linhas por
segundo.

A otimização de produção seria ler linhas cruas com `DB::table()` e um join, em
vez de models — troca-se a conveniência do domínio (o enum de status, o
`isOverdue()`) por velocidade. Não foi feita aqui porque o requisito é não
estourar memória, e isso está cumprido e medido.

### Formato do arquivo

Delimitador **ponto e vírgula** e decimais com vírgula, mais BOM UTF-8. Quem
abre um relatório de faturamento abre no Excel em português, onde a vírgula é
separador decimal e o ponto e vírgula é o delimitador esperado. Sem o BOM, o
Excel lê UTF-8 como Latin-1 e os acentos viram lixo.

É uma escolha pelo destinatário, não pelo parser: para consumo programático, o
CSV padrão com vírgula seria melhor.

### O download passa por Route Handler

O browser não tem o token — ele vive num cookie `httpOnly` — então não consegue
chamar o endpoint de exportação por conta própria. O Route Handler do Next
anexa o `Bearer` e repassa o corpo.

O corpo é repassado **sem ser lido**: `upstream.body` é um `ReadableStream`, e
consumi-lo para reenviar depois guardaria o arquivo inteiro na memória do Next,
anulando o streaming do backend. Verificado: o download pelo Next mantém o
primeiro byte em 0,69s contra 2,38s de total.

---

## Exportação em PDF

`GET /api/reports/billings/pdf`, e o botão **Exportar PDF** na tela do
relatório. Mesmo conteúdo do CSV: período e filtros no cabeçalho, as linhas, e
os totalizadores.

Biblioteca: **`barryvdh/laravel-dompdf`**. PHP puro, sem binário externo — o
que evita embarcar um Chrome no container, como exigiria a alternativa baseada
em Browsershot.

### O PDF tem teto, e o teto saiu de medição

Diferente do CSV, aqui **não existe streaming**, e isso é da natureza do
formato: um PDF precisa ser paginado e montado inteiro antes de existir, porque
não há como emitir a página 1 sem saber quantas páginas haverá.

O plano inicial deste projeto previa teto de 5.000 linhas. **Ele não sobreviveu
à medição.** Consumo real do dompdf neste relatório, com nove colunas:

| Linhas | Pico de memória | Tempo | PDF gerado |
|---|---|---|---|
| 500 | 184 MB | 9,6s | 926 KB |
| 1.000 | 420 MB | 17,9s | 994 KB |
| 2.000 | 1.164 MB | 56,5s | 1.131 KB |
| 3.500 | 2.965 MB | 210,0s | 1.337 KB |
| 5.000 | **estourou 3 GB** | — | — |

O crescimento é **superlinear**: dobrar as linhas quase triplica a memória. A
causa é estrutural — o dompdf constrói uma árvore de frames e um *cellmap* da
tabela inteira antes de paginar, então uma tabela de 5.000 linhas por 9 colunas
vira 45.000 células como objetos vivos simultaneamente.

Decisões que saíram daí:

- **`pdf_max_rows` é 1.000**, não 5.000. É o maior valor que cabe com folga.
- **`memory_limit` é 512M** e `max_execution_time` é 120s, em
  `backend/docker/php/app.ini`. O default de 128M derrubava a geração com 1.810
  linhas, e o de 30s a derrubava antes mesmo da memória acabar.

Acima do teto a resposta é **422**, com uma mensagem que diz o que fazer:

```json
{
  "message": "O relatório tem 1.810 cobranças e o limite do PDF é 1.000. Use a exportação em CSV, que não tem limite.",
  "count": 1810,
  "limit": 1000
}
```

A contagem vem da consulta de agregação, então **nenhuma linha é carregada para
descobrir que são linhas demais** — e os mesmos totais são reaproveitados no
rodapé do documento, sem consulta extra.

A tela não deixa o usuário descobrir isso batendo num erro: o relatório informa
`export.pdf_available`, e o botão vira um aviso apontando o CSV quando o
recorte não cabe.

Se o PDF em volume fosse requisito real, o caminho seria trocar o renderizador
por um que escreva página a página — `FPDF` ou `TCPDF` emitem linhas
incrementalmente e não montam a árvore inteira. Ficaria mais feio e mais
trabalhoso de estilizar, o que é a troca certa quando o volume manda.

### Testes

Nenhuma asserção sobre o binário: o conteúdo de um PDF gerado não é estável
nem legível, e testar bytes seria teste que quebra sozinho. O que se afirma é o
status, o `Content-Type`, a assinatura `%PDF-`, e sobretudo o comportamento do
teto — inclusive que ele considera o **conjunto filtrado** e não o tamanho da
tabela, senão a exportação seria inútil em qualquer base real.

---

## Gerando volume para teste

```bash
make seed-volume                            # docker compose exec php php artisan db:seed --class=BillingVolumeSeeder
```

Gera 5.000 clientes e **2.000.000 de cobranças**, com emissão espalhada por
três anos para o filtro de período ter o que recortar. Para uma amostra menor:

```bash
docker compose exec -e BILLING_SEED_COUNT=100000 php     php artisan db:seed --class=BillingVolumeSeeder
```

Ele não roda no `DatabaseSeeder` de propósito — são minutos de execução, e não
é o que se quer a cada `db:seed`.

**Insert em lote, não factory registro a registro.** A factory instancia um
model, dispara eventos e faz um INSERT por linha; em dois milhões de cobranças
a diferença não é percentual, é de ordem de grandeza. O seeder monta arrays
crus e insere em blocos de 2.000, com o query log desligado — sem isso o
Laravel acumula cada INSERT em memória e o processo morre antes do fim.

O seeder **trunca as tabelas antes de começar**. Os documentos dos clientes são
sequenciais para garantir unicidade sem consultar o banco, o que tornaria uma
segunda execução impossível sobre os dados da primeira; e medir consulta sobre
volume acumulado de execuções anteriores não diria nada.

Uma exceção: ele **não trunca tabela já vazia**. `TRUNCATE` é DDL e custa ~7s
por tabela nesta base mesmo sem ter o que apagar, e há um efeito colateral pior
do que o tempo — descrito em [Testes](#o-teste-do-seeder-não-emite-ddl).

### Pagas em atraso, com juros congelados de verdade

Quarenta por cento das cobranças nascem pagas, e **35% dessas foram pagas com
atraso** — com `paid_amount` e `paid_interest_amount` calculados, não zerados.

Sem isso a base de medição não exercita a regra que mais importa no domínio: o
relatório mostraria R$ 0,00 de juros recebidos, e a tela de detalhe de uma
cobrança paga nunca teria juros congelados para exibir. Uma base de dois
milhões de linhas em que a regra central nunca aparece não é base de medição, é
volume.

**O valor congelado vem do `RegisterPayment`**, o mesmo serviço que a API usa
quando alguém registra um pagamento pela tela. O seeder decide *quando* a
cobrança foi paga e mais nada. Para isso o serviço ganhou `freeze()`, que
devolve as colunas do pagamento sem gravá-las:

| | quem chama | o que faz com o retorno |
|---|---|---|
| `__invoke()` | API, factory | `update()` no model |
| `freeze()` | seeder de volume | vira campo da linha do insert em lote |

A alternativa era escrever `valor * POW(1 + taxa, dias/30)` dentro do seeder.
Seria mais rápido e estaria errado: a base de medição passaria a validar uma
cópia da regra, e uma divergência entre as duas só apareceria quando alguém
comparasse a tela com o relatório. `BillingVolumeSeederTest` fecha essa porta —
ele reconstrói a cobrança semeada como pendente, paga pelo serviço de produção
na mesma data e exige igualdade até o centavo.

Dois limites, ambos com motivo:

- **Atraso de no máximo 120 dias.** Sem teto, uma cobrança vencida há três anos
  paga a 5% ao mês acumularia `1,05^36` — quase seis vezes o valor original.
  Acontece, mas não é o que uma base de faturamento parece.
- **Pagamento nunca cai no futuro.** A versão anterior pagava sempre de 1 a 25
  dias antes do vencimento, e para cobrança que ainda vai vencer isso produzia
  data de pagamento depois de hoje. Cobrança cujo vencimento está a mais de 25
  dias de distância simplesmente nasce pendente.

### Quanto custa congelar

Medição A/B na mesma máquina e na mesma sessão, 200.000 cobranças inseridas na
tabela com os sete índices do relatório:

| | total | PHP | INSERT | linhas/s |
|---|---|---|---|---|
| Sem congelamento | 330,1s | 6,5s | 323,5s | 606 |
| Com congelamento | 364,1s | 32,8s | 331,3s | 549 |

O congelamento custa **0,29 ms por cobrança paga** — 26s a mais por 200.000
linhas, ou +10% no total. O que domina é o INSERT, com 90% do tempo: o seeder é
limitado pelo banco, não pelo PHP, e é por isso que trocar o `RegisterPayment`
por uma fórmula inline compraria pouco e custaria a fonte única da regra.

O detalhe do custo, medido em 20.000 iterações isoladas: `new Billing()` 0,052
ms, `InterestCalculator::for()` 0,150 ms, o resto é a escolha da data.

---

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
| **Linhas** | **99,84%** (627/628) |
| Métodos | 99,08% (108/109) |
| Classes | 96,88% (31/32) |

Usa **pcov**, não xdebug: ele existe só para cobertura e custa uma fração do
tempo. Fica desligado por padrão (`pcov.enabled = 0`) para não pesar na
execução normal, e é ligado na linha de comando.

O `-d` precisa ir direto no `phpunit` porque `artisan test --coverage` roda o
PHPUnit em subprocesso e a flag não propaga — ele responde
"No code coverage driver available" mesmo com a extensão carregada.

**A linha não coberta**, e por quê: `BillingReportCsvExport.php:68`, o
`flush()` que dispara a cada 500 linhas escritas. Cobri-la exigiria criar 500
cobranças num teste para afirmar um efeito colateral sem resultado observável.
Fica descoberta de propósito — perseguir o último ponto percentual produziria
um teste pior, não um sistema melhor.

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

## Integração contínua

`.github/workflows/ci.yml` roda a cada push a mesma verificação que `make test`
e `make lint` fazem na máquina, em **dois jobs paralelos** — backend e frontend
não dependem um do outro para serem verificados, e assim o typecheck não espera
os minutos da suíte para falhar.

| Job | O que roda |
|---|---|
| Backend | MySQL 8 como serviço, PHP 8.3 com `pdo_mysql` e `bcmath`, `pint --test`, `php artisan test` |
| Frontend | Node 22, `npm ci`, `next typegen`, `tsc --noEmit`, `eslint` |

As versões e as extensões não foram escolhidas de novo: são as dos Dockerfiles,
e o banco da suíte é o `faturamento_test` com as credenciais que o
`phpunit.xml` espera. O `MYSQL_DATABASE` do serviço cria o banco no primeiro
boot, o que dispensa no CI o script de init que o Compose usa.

### O job roda no runner, e não dentro de um container

A [documentação de containers de
serviço](https://docs.github.com/en/actions/tutorials/communicating-with-docker-service-containers)
é explícita sobre a diferença, e ela decide o desenho: job **dentro de um
container** alcança o serviço pelo rótulo (`mysql`), sem publicar porta; job no
**runner** alcança por `localhost`, e a porta precisa ser publicada.

O caminho do container era tentador, porque o rótulo `mysql` é exatamente o
`DB_HOST` que o `phpunit.xml` fixa — zero variável de ambiente a mais. Ficou de
fora porque dentro de um `php:8.3-cli` seria preciso compilar as extensões e
instalar o Composer à mão, enquanto no runner o `setup-php` entrega os três.

O preço é uma variável: `DB_HOST: 127.0.0.1`. E ela funciona por um detalhe do
PHPUnit que eu **conferi antes de escrever o workflow**, em vez de assumir: o
`<env>` do `phpunit.xml` não sobrescreve variável de ambiente que já existe, só
com `force="true"`. Rodando a suíte com a variável presente, o erro de conexão
nomeou o host — `Host: 127.0.0.1` —, provando quem vence. O `phpunit.xml`
continua sendo a fonte da verdade para o ambiente documentado, o do Compose.

### O CI achou um problema no primeiro push

A primeira execução **falhou** — e não no workflow, no projeto. O backend
passou em 56s; o frontend quebrou com `Cannot find name 'LayoutProps'`.

`LayoutProps` e `PageProps` são tipos **gerados** pelo Next em `.next/types`, e
o `tsconfig.json` os inclui. Na máquina eles já existiam, criados pelo servidor
de desenvolvimento — então `make lint` passava. Num checkout limpo ninguém os
criou, e o `tsc` não os encontra. O alvo local tinha o mesmo furo e ninguém
notaria até alguém clonar o repositório e rodar a verificação antes de subir a
aplicação.

A correção é um passo, `next typegen`, que gera só as definições sem o build
inteiro, e foi aplicada **nos dois lugares** — no CI e no `make lint` —, porque
o problema era dos dois. É o primeiro retorno concreto do pipeline: ele não
serviu para confirmar o que já se sabia, serviu para mostrar o que a máquina de
desenvolvimento escondia.

Na mesma execução vieram avisos de que `actions/checkout@v4`, `setup-node@v4` e
`cache@v4` rodam sobre Node 20, descontinuado. As versões correntes foram
conferidas pela API do GitHub, não pela memória — `checkout v7`, `setup-node
v7`, `cache v6` — e o workflow subiu para elas.

### Três ajustes que valem o comentário

- **Pint antes da suíte.** Ele leva segundos e a suíte leva minutos; descobrir
  formatação errada depois de esperar a suíte é desperdício.
- **`concurrency` com `cancel-in-progress`.** Push novo no mesmo ref cancela a
  execução anterior, cujo resultado já não descreve o código atual.
- **`permissions: contents: read`.** Nada aqui escreve no repositório, e um
  token com escrita seria superfície que este workflow não precisa.

Cobertura fica de fora do CI: o `pcov` instrumenta o código e o relatório é
coisa de `make coverage`, rodado quando se quer olhar. E a suíte roda em MySQL
no CI pelo mesmo motivo que roda no Compose — [em SQLite ela validaria outro
motor](#banco-de-testes).

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

---

## Estados de erro e carregamento

Quatro arquivos de convenção do App Router, e nenhum deles é decorativo.

| Arquivo | Cobre |
|---|---|
| `app/error.tsx` | Tudo que falha fora do grupo `(app)`: o `/login`, e a falha do próprio layout autenticado |
| `app/not-found.tsx` | URL inexistente e o `notFound()` das telas de detalhe |
| `app/(app)/error.tsx` | A área autenticada, preservando o cabeçalho |
| `app/(app)/{clientes,cobrancas}/[id]/loading.tsx` | Esqueleto das telas de detalhe |

**`retry`, não `reset`.** Esta é a parte que não se descobre lendo código. A
fronteira de erro recebe os dois, e eles fazem coisas diferentes: `retry()`
refaz o fetch e re-renderiza; `reset()` só limpa o estado de erro e
reaproveita o payload que já falhou. Para queda de API — que é o caso real —
`reset()` reexibe exatamente o mesmo erro, e o botão "Tentar de novo" vira
enfeite.

Foi assim que o defeito apareceu: com a tela aberta, `docker compose stop
backend`, recarregar, religar o backend e clicar no botão. Com `reset`, nada
acontecia. Com `retry`, a tela volta. Os dois arquivos de erro usam `retry`.

**Altura `flex-1`, não `min-h-screen`.** O `app/not-found.tsx` renderiza em
dois contextos: sozinho no layout raiz, quando a URL não existe, e **dentro do
cabeçalho da aplicação**, quando uma tela de detalhe chama `notFound()`. No
segundo caso, uma altura de viewport inteira abaixo do cabeçalho produz scroll
vertical. Visto em 360px antes de virar commit.

**Esqueleto próprio nas telas de detalhe.** Sem eles, o detalhe herdaria o
`loading.tsx` da listagem — o esqueleto de uma tabela larga, que não se parece
com a tela que vai aparecer. O salto de um layout para o outro é pior do que
não ter esqueleto nenhum.

O que fica de fora, e por quê: `global-error.tsx`. Ele cobriria erro lançado
pelo layout raiz, mas precisa reconstruir `<html>` e `<body>` e não herda o
CSS global. O layout raiz deste projeto monta a página e carrega a fonte, nada
mais — o custo não se paga.

---

## Perfis de acesso

Dois perfis: **administrador**, que opera, e **consulta**, que lê tudo e não
escreve nada.

| | Administrador | Consulta |
|---|---|---|
| Ver clientes, cobranças, relatório e painel | sim | sim |
| Exportar CSV e PDF | sim | **sim** |
| Cadastrar, editar e importar | sim | não |
| Registrar pagamento | sim | não |

Exportar é leitura, e fica do lado de quem consulta: o arquivo é o mesmo
relatório em outro formato, e recusá-lo a quem pode ver a tela seria proteger o
dado do lugar errado.

### A barreira é o backend, não a tela

A interface esconde o que o perfil não pode fazer, e isso é **conveniência**.
Quem sabe o endereço do endpoint chega nele sem passar por tela nenhuma:

```bash
curl -X POST localhost:8000/api/customers -H "Authorization: Bearer <token de consulta>"
# 403 — Seu perfil é de consulta e não permite esta operação.
```

`RoleAccessTest` bate direto na API, sem tela no caminho, e cobre **todo**
endpoint que escreve. Um endpoint de escrita novo que não aparecer lá fica sem
teste, que é o sinal seguinte.

Quem digitar o endereço de uma tela de escrita recebe a explicação — "seu perfil
é de consulta" — e não um formulário que vai falhar no envio nem um 404
mentiroso: a página existe, o que falta é permissão.

### Middleware e não Policy

Policy resolve autorização **por registro**: "este usuário pode editar ESTA
cobrança". A regra aqui é por **perfil** e vale para todo registro, então ela
está amarrada ao grupo de rotas.

O ganho é o `routes/api.php`: dá para ler quais rotas escrevem olhando o
arquivo, porque elas estão num grupo só, com `can.write`. Espalhada por uma
classe de política para cada model, a mesma informação exigiria abrir quatro
arquivos.

### Dois defaults que parecem se contradizer

A coluna `role` tem default **`viewer`** — o menor privilégio. Um usuário criado
por um caminho que esqueceu de definir o perfil não sai escrevendo, que é o
comportamento seguro quando alguém erra.

A factory de testes cria **`admin`**. Não é contradição: o default do banco
protege produção, e a factory serve a dezenas de testes que precisam escrever e
não têm nada a ver com perfil. O default oposto ali faria todos eles falharem
com 403 por um motivo que não é o deles.

E os usuários que já existiam quando a migration rodou viraram administradores,
apesar do default: antes dela não havia outro perfil, então quem estava lá era
administrador por definição. Aplicar o default a eles tiraria o acesso de quem
já operava o sistema.

---

## Idempotência no pagamento

Registrar pagamento é a operação da API em que repetir **cobra duas vezes** — e,
desde o [estorno](#estorno-de-pagamento), não é mais a única em que repetir muda
dinheiro de lugar. Criar dois clientes iguais esbarra no índice único do
documento; reimportar um CSV devolve o relatório do que gravou. Pagar duas vezes
grava dois valores congelados, e o segundo é o de outro dia.

O usuário não precisa fazer nada de errado para isso acontecer: um clique duplo,
uma conexão que cai depois de o servidor ter processado, um `F5` na tela de
confirmação. A tela desabilita o botão enquanto envia, e isso resolve o caso
fácil e nenhum dos outros — é a mesma história dos [perfis de
acesso](#a-barreira-é-o-backend-não-a-tela): o que vale é o que o backend
garante.

A operação aceita o cabeçalho **`Idempotency-Key`**, e com ele a segunda chamada
devolve o resultado da primeira em vez de processar de novo.

```
POST /api/billings/787/payment
Idempotency-Key: a446dee2-f551-4b68-a4ed-344dddf7301b

HTTP/1.1 200 OK
{"data":{"paid_amount":"13605.62","paid_interest_amount":"9593.85", ...}}

  ↓ a mesma chamada, de novo

HTTP/1.1 200 OK
Idempotent-Replay: true
{"data":{"paid_amount":"13605.62","paid_interest_amount":"9593.85", ...}}
```

Os dois corpos são idênticos byte a byte. O cabeçalho `Idempotent-Replay` é a
única diferença, e existe para quem chama distinguir "pagou agora" de "já tinha
pago" no log — o corpo sozinho não conta essa história.

O nome do cabeçalho não foi inventado: é o do rascunho da IETF
(`draft-ietf-httpapi-idempotency-key-header`), que é o mesmo que Stripe e
Adyen usam. Escolher um nome próprio obrigaria a explicá-lo a cada integração.

### O índice único é o mecanismo, não a validação

A reserva da chave é um `INSERT` numa tabela com `UNIQUE (user_id, key)`, feito
**antes** de processar. A alternativa óbvia — consultar se a chave existe e
inserir se não existir — tem uma janela entre as duas consultas em que duas
requisições simultâneas passam as duas. E requisições simultâneas não são o caso
raro aqui: são o caso principal, porque é assim que o clique duplo chega.

Com o `INSERT` primeiro, quem arbitra é o banco. Quem perde a corrida recebe a
violação de unicidade e vai olhar o estado da linha para decidir o que fazer.

A linha nasce com `response_status` nulo, e esse estado — *reservada, ainda sem
resposta* — é o que permite responder **409** para quem chega enquanto a
primeira ainda processa. Sem ele, a segunda requisição não teria como saber se a
chave está em uso ou se a resposta simplesmente não existe.

Medido contra a base de 2.000.000 de cobranças, oito requisições disparadas ao
mesmo tempo na mesma cobrança com a mesma chave:

| Desfecho | Quantas |
|---|---|
| `200` — processou o pagamento | 1 |
| `200 Idempotent-Replay` — recebeu o resultado guardado | 1 |
| `409` — chegou com a primeira em voo | 6 |

Uma cobrança, um pagamento. Sem a chave, as oito teriam disputado a mesma
cobrança e o resultado dependeria de quem chegasse primeiro no `UPDATE`.

### Middleware, e não código no controller

A resposta guardada precisa incluir **os erros de validação**: repetir uma
chamada que falhou tem que repetir a falha, não processá-la. E o 422 do
`RegisterPaymentRequest` nasce antes de o controller existir — no controller não
haveria o que guardar.

Do middleware dá para guardar o que a rota respondeu, independente de quem
respondeu. Isso funciona porque o `Illuminate\Routing\Pipeline` renderiza a
exceção dentro da pilha: o middleware recebe o 422 já como resposta, não como
`ValidationException`.

O middleware é registrado como alias `idempotent` e aplicado a duas rotas: o
pagamento e o estorno. Não é preguiça: aplicá-lo ao grupo de escrita inteiro
criaria linha de tabela para toda importação de CSV e todo cadastro de cliente,
sem cobrir risco nenhum.

### O que a chave NÃO faz

Três recusas de propósito, e todas têm teste:

**Sem o cabeçalho, nada muda.** Pagar uma cobrança já paga continua respondendo
422. Idempotência serve a quem repete a **mesma** operação — transformar toda
segunda tentativa em sucesso esconderia um erro de verdade.

**Mesma chave com outro conteúdo responde 422**, e "outro conteúdo" inclui outra
cobrança: a impressão digital comparada é o método, o caminho e o payload. Um
cliente que reaproveita chave está com bug, e devolver o resultado antigo
esconderia o bug em vez de apontá-lo.

**Erro de servidor devolve a chave.** Um 500 não é resultado da operação, é falha
em produzi-lo, e o certo depois de um 500 é tentar de novo. Guardá-lo
condenaria a chave a repetir a falha pelas 24 horas seguintes.

### A validade é de 24 horas

Guardar para sempre não é opção: a tabela cresceria sem teto, e uma chave de
meses atrás repetiria uma resposta que já não descreve o registro. Vinte e
quatro horas cobre com folga o que a idempotência existe para cobrir: clique
duplo, retry de rede, reenvio de formulário.

A limpeza das vencidas é **por sorteio** — uma chance em duzentas, a cada chave
nova. É a mesma estratégia que o Laravel usa para expirar sessão em arquivo, e a
razão é a mesma: manutenção não pode custar um `DELETE` em toda operação de
escrita. Tarefa agendada seria mais previsível, mas este projeto não sobe
worker — agendar aqui seria escrever uma limpeza que nunca roda.

### De onde a chave vem, na tela

O formulário de pagamento sorteia um UUID **no browser**, e o reaproveita
enquanto o conteúdo dos campos não muda:

- **conteúdo igual → mesma chave.** É o clique duplo e o reenvio depois de a
  conexão cair. O backend devolve o primeiro resultado.
- **conteúdo mudou → chave nova.** Quem corrigiu a data depois de um erro está
  pedindo outra coisa; reaproveitar a chave devolveria o 422 antigo.

A chave não pode nascer na Server Action. Uma action reexecutada por retry de
rede rodaria o sorteio de novo e produziria outra chave — que é exatamente o
caso que a chave existe para cobrir. Nascendo no cliente, o reenvio manda a
mesma.

Ela também não pode nascer durante a renderização: `crypto.randomUUID()` daria
um valor no servidor e outro na hidratação. Por isso o envio passa por
`onSubmit` com a action dentro de uma transição, o mesmo padrão que a
[importação](#o-arquivo-não-fica-guardado-entre-a-prévia-e-a-confirmação) já
usava por outro motivo.

---

## Trilha de auditoria

Toda **edição**, todo **pagamento** e todo **estorno** de cobrança registram quem
alterou, o quê e quando. A trilha é lida em
`GET /api/billings/{id}/audit` e aparece no fim da página da cobrança, como
"Histórico de alterações" — inclusive para o perfil de consulta, porque ler o
histórico é leitura.

Cada entrada guarda **só o que mudou**, com o valor de antes e o de depois:

```json
{
  "event": "paid",
  "event_label": "Pagamento registrado",
  "user": { "id": 1, "name": "Administrador" },
  "changes": [
    { "field": "status",               "from": "pending", "to": "paid" },
    { "field": "payment_date",         "from": null,      "to": "2026-09-14" },
    { "field": "paid_amount",          "from": null,      "to": "9996.90" },
    { "field": "paid_interest_amount", "from": null,      "to": "3677.72" }
  ],
  "created_at": "2026-09-14T12:54:17+00:00"
}
```

A entrada de pagamento carrega os valores congelados no `to`, e a de estorno os
traz no `from`: as colunas de pagamento da cobrança são limpas, e o que foi pago
continua registrado aqui.

Uma trilha vale pelo que garante, e são três garantias — cada uma com teste.

### Completa: observer, e não chamada explícita

A trilha é gravada por um observer do Eloquent em `Billing`, e não por uma
chamada em cada ponto que altera cobrança. Chamada explícita é exatamente o tipo
de coisa que o próximo caminho de escrita esquece; o observer pega toda
alteração pelo Eloquent, venha do controller, do registro de pagamento ou do
tinker.

O **evento sai da transição de status**, não de quem chamou. Nenhum ponto do
código declara "isto é um pagamento": pendente que vira paga é pagamento, paga
que volta a pendente é estorno, venha de onde vier. Nenhum caminho novo consegue
rotular errado — o estorno entrou na trilha sem uma linha nova no observer, só
com um caso a mais no enum.

O preço do observer é conhecido: **consulta crua passa por fora sem aviso.** Um
`DB::table('billings')->update(...)` altera a cobrança e a trilha nunca fica
sabendo. Por isso existe um teste que varre `app/` atrás desse padrão — e o
limite dele fica dito no próprio teste: pega a escrita encadeada na mesma
instrução, não o construtor guardado numa variável e alterado três linhas
depois. Ele existe para o erro óbvio não passar na revisão, não para
substituí-la.

As bibliotecas conhecidas para isso — `spatie/laravel-activitylog` e
`owen-it/laravel-auditing` — foram consideradas e ficaram de fora. As duas se
apoiam nos mesmos eventos do Eloquent, então a porta da consulta crua continuaria
aberta do mesmo jeito; guardam tudo numa tabela polimórfica genérica, pensada
para auditar muitos models, quando aqui há um; e a distinção entre pagamento e
edição precisaria ser escrita por cima delas de qualquer forma. O que existe
aqui é um observer, um enum e um model.

### Atômica: sem trilha, sem alteração

A edição e o pagamento gravam com `updateOrFail`, que abre transação. O observer
escreve a trilha dentro dela, então se a escrita da trilha falhar, a alteração
volta junto. Uma cobrança alterada sem registro na trilha é o furo que uma
trilha não pode ter.

O teste simula a falha no evento `creating` do próprio model da trilha, e não
renomeando a tabela: DDL no meio do teste encerraria a transação do
`RefreshDatabase` por commit implícito — a [armadilha que já custou 50
segundos por teste](#o-teste-do-seeder-não-emite-ddl).

### Imutável: registro errado se corrige com outro registro

O model da trilha **recusa `update` e `delete`** com exceção, e a tabela não tem
`updated_at` porque não há o que atualizar. As chaves estrangeiras são
`RESTRICT`: apagar uma cobrança ou um usuário que tem histórico falha, em vez de
levar o histórico junto.

A recusa vale para todo caminho que passe pelo Eloquent, e só para ele. SQL cru
com o usuário da aplicação ainda altera a tabela. Fechar isso de verdade é
permissão no banco — um usuário de aplicação sem `UPDATE` e `DELETE` em
`billing_audits` —, e este projeto usa um usuário só para a aplicação e para as
migrations. Fica dito aqui em vez de parecer resolvido.

### Só entra o que mudou de fato

Quem decide o que mudou são os casts do model. O valor que chega como `"1000"`
sobre um `"1000.00"` gravado é o mesmo número, o Eloquent não o marca como
alterado, e ele não aparece na trilha — registrar isso encheria o histórico de
ruído que esconde a alteração verdadeira. Os carimbos `updated_at` e
`created_at` ficam de fora pelo mesmo motivo, e uma edição que não muda nada não
registra nada.

### A criação fica de fora, de propósito

O que o enunciado pede na trilha é edição, pagamento e estorno — alterações. O
quando da criação já está em `created_at`.

Registrar a criação **de forma consistente** exigiria o id de cada cobrança que a
importação grava, e a importação grava em lote, com um `INSERT` de quinhentas
linhas. O MySQL não devolve os ids de um insert em lote, e com
`innodb_autoinc_lock_mode = 2` — o default do MySQL 8, conferido neste servidor
— os ids de um mesmo insert em lote não têm garantia de serem consecutivos
quando há inserts concorrentes. Calcular `LAST_INSERT_ID() + n` seria um
palpite. As alternativas eram gravar linha a linha, desfazendo o lote que a
importação usa, ou registrar a criação só para quem cadastra pela tela.

A segunda é pior do que não registrar: uma trilha em que metade das cobranças
tem "criada" e a outra metade não **mente por omissão**. A trilha começa, para
toda cobrança igual, na primeira alteração.

O seeder de volume também não grava trilha. Os dois milhões de linhas entram por
insert cru, e as pagas recebem os valores congelados na própria linha, pelo
`RegisterPayment::freeze()` — não houve alteração feita por alguém para
registrar. O `truncate()` do seeder passou a limpar `billing_audits` junto,
porque o `TRUNCATE` reinicia os ids das cobranças e a trilha antiga passaria a
descrever cobranças que não são as dela.

### O MySQL reordena as chaves do JSON

`changes` é uma coluna JSON, e o MySQL não guarda a ordem das chaves: ele
reordena por tamanho. `{"from": "a", "to": "b"}` volta do banco como
`{"to": "b", "from": "a"}`, e `description` volta depois de `due_date`. Foi o
teste que mostrou, com três asserções falhando por ordem e não por conteúdo.

A ordem que a API entrega é imposta pelo resource — `field`, `label`, `from`,
`to`, e os campos na ordem da ficha da cobrança, com o status primeiro —, e o
teste compara o conteúdo gravado sem depender da ordem, mas com tipos estritos:
`assertEquals` resolveria a ordem aceitando `null` igual a `''`, e o `from` nulo
do pagamento é justamente o que importa.

### A leitura, medida com volume

A trilha não tem índice além dos das chaves estrangeiras, e não precisa. A
leitura é `WHERE billing_id = ? ORDER BY id DESC LIMIT 50`, e o índice que a
chave estrangeira cria em `billing_id` já está em ordem de id dentro de cada
cobrança: no InnoDB o índice secundário carrega a chave primária no fim.

Uma tabela vazia não prova isso — o otimizador escolhe outro plano quando não há
linhas. A medição foi feita com **200.080 entradas sintéticas** na base de
2.000.000 de cobranças, 81 delas numa cobrança só, apagadas depois:

| Consulta | Plano | Tempo |
|---|---|---|
| página de 50 da cobrança | `Index lookup ... (reverse)` na FK, sem filesort | 0,127 ms |
| contagem para a paginação | `Covering index lookup` na FK | 0,049 ms |

---

## Estorno de pagamento

O estorno desfaz um pagamento que não se sustentou — cheque devolvido,
transferência revertida, baixa lançada na cobrança errada.
`POST /api/billings/{id}/reversal`, sem corpo, e na tela um cartão "Estornar
pagamento" na cobrança paga, para o perfil de administrador.

Três regras, cada uma com teste:

- **A cobrança volta a pendente**, com data e valores de pagamento nulos.
- **Os valores congelados não somem.** Vão para a trilha de auditoria, no `from`
  da entrada de estorno, e a entrada do pagamento original continua lá, intacta.
- **Os juros voltam a correr desde o vencimento original.**

### Desde o vencimento, e não de outra data

Havia três datas candidatas para o relógio dos juros recomeçar, e elas dão três
valores diferentes. Uma cobrança de R$ 1.000,00 a 2% ao mês, vencida em 16/05,
paga em 20/05 e estornada em 15/06:

| Juros correndo desde | Dias | Valor em 15/06 |
|---|---|---|
| **o vencimento, 16/05** | **30** | **R$ 1.020,00** |
| o pagamento, 20/05 | 26 | R$ 1.017,31 |
| o estorno, 15/06 | 0 | R$ 1.000,00 |

A regra é a primeira. O pagamento que não se sustentou não aconteceu para o
devedor: ele continua devendo desde o vencimento, e contar do pagamento ou do
estorno transformaria o estorno num desconto de juros para quem pagou com um
cheque sem fundo.

### Não precisou de regra nova de juros

O `ReversePayment` só limpa as colunas de pagamento e devolve o status a
pendente. Os juros voltam a correr sem nenhuma linha no cálculo, porque o
`InterestCalculator` só lê as colunas congeladas quando a cobrança está paga —
nas duas faces. É a [regra que governa a arquitetura](#cálculo-de-juros)
pagando de novo: se o valor atualizado dependesse de algo gravado no pagamento
além dessas colunas, o estorno teria que desfazê-lo em dois lugares.

Conferido na base de 2.000.000 de cobranças com uma cobrança paga em 2023, dois
dias depois do vencimento, por R$ 1.291,90. Estornada, ela passou a valer
**R$ 7.302,09** nos três lugares em que o valor pode ser lido: o endpoint da
cobrança (face PHP), a expressão SQL que a listagem e o relatório usam no
`SELECT`, e a conta feita à parte, com os 1.067 dias desde o vencimento.

A cobrança estornada pode ser paga de novo, e aí os juros congelam na nova data.

### Estorno e idempotência

O estorno aceita `Idempotency-Key`, e o caso que justifica é concreto: pagou,
estornou, pagou de novo — e o retry atrasado do estorno chega. Sem a chave, ele
**estornaria o segundo pagamento**, que ninguém pediu para estornar. Com a
chave, recebe o resultado do estorno original.

O outro lado da mesma pergunta pedia uma decisão: **o estorno invalida a chave
do pagamento?** Não, e é de propósito. A chave descreve a operação de pagar, que
aconteceu. Um retry atrasado do pagamento que chegue depois do estorno recebe o
resultado original, com `Idempotent-Replay`, em vez de pagar de novo.
Invalidar a chave no estorno transformaria esse retry exatamente no pagamento
em dobro que ela existe para impedir. Quem quer pagar de novo depois de um
estorno faz uma operação nova, com chave nova — que é o que a tela faz, porque
o formulário de pagamento que reaparece é outra montagem, com outro sorteio.

Os dois testes que cobrem isso acontecem no mesmo dia, de propósito: a chave vale
24 horas, e um teste que viajasse de maio a junho a venceria e passaria pelo
motivo errado.

Escrevê-los mostrou uma armadilha do próprio harness: `withHeaders()` guarda o
cabeçalho para **todas** as requisições seguintes do teste. A chave do pagamento
vazava para o estorno "sem chave", o middleware respondia 422 de chave
reaproveitada, e o teste falhava pelo motivo errado. Ficou registrada como a
armadilha 7 da skill de testes.

### Sem campo de motivo

Um motivo do estorno seria o campo óbvio, e ficou de fora. O enunciado não o
pede, e o quem e o quando já estão na trilha. Acrescentá-lo não é só um campo no
formulário: a trilha é gravada por um observer que só enxerga o model, e o
motivo não é coluna da cobrança. Seria preciso uma coluna em `billing_audits` e
um jeito de passar contexto da requisição para o observer — mecanismo que só
vale construir quando houver o requisito.

### Na tela: dois passos, sem modal

O primeiro clique só abre a confirmação, que diz o que vai acontecer com o
dinheiro antes de acontecer. O botão de confirmar usa a variante destrutiva, que
neste sistema é a cor de vencida — no domínio, vermelho já significa perda.
Modal seria mais um componente para uma pergunta de uma linha, e tiraria de
vista os valores pagos que estão logo acima, que são justamente o que a pessoa
precisa conferir antes de estornar.

### Quanto custa, e para onde vai o tempo

O primeiro estorno na base real levou 3,3 segundos, e esse número não podia
ficar sem explicação. A primeira hipótese — o buffer pool de 128 MB forçando
leitura de disco nos índices que contêm status e colunas de pagamento — **caiu
na medição**: escritas com zero páginas lidas do disco levavam o mesmo tempo.

O que o tempo acompanha é o **número de transações de escrita**. Medido na base
de 2.000.000 de cobranças, três repetições por cenário, com os contadores do
MySQL calibrados contra o que as próprias consultas de medição somam:

| Requisição | Tempo mediano | Transações de escrita |
|---|---|---|
| GET de uma cobrança | 0,40 s | 1 |
| pagamento, sem chave | 0,46 s | 2 |
| estorno, sem chave | 0,58 s | 2 |
| pagamento, com chave | 1,41 s | 4 |
| estorno, com chave | 1,23 s | 4 |

O trabalho do estorno no banco é pequeno: o `UPDATE` da cobrança e o `INSERT` da
trilha somam **4 ms** medidos direto no MySQL, dentro de uma transação desfeita.
O resto é commit. Um commit isolado — um `INSERT` de uma linha em autocommit —
leva **de 183 a 360 ms** neste ambiente, porque a durabilidade está no máximo
(`innodb_flush_log_at_trx_commit = 1`, `sync_binlog = 1`, binlog ligado: redo e
binlog vão ao disco a cada commit) e o disco é o do Docker dentro do WSL2. Num
servidor com disco decente o mesmo fsync custa milissegundos; a proporção entre
as linhas da tabela é o que se leva daqui.

As transações de cada requisição, uma a uma:

- **`last_used_at` do token.** O Sanctum grava a cada requisição autenticada que
  cai num segundo novo — inclusive no GET, que por isso faz um commit para ler.
  Duas leituras no mesmo segundo levaram 0,51 s e 0,03 s.
- **A transação do estorno ou do pagamento**, com a trilha dentro.
- **A reserva da chave de idempotência**, antes de processar, e **a resposta
  guardada**, depois. São as duas que a chave acrescenta, e não dá para
  juntá-las à transação do negócio: a reserva precisa estar gravada *antes* de
  processar, senão a requisição concorrente não a enxerga e as duas processam —
  que é o caso inteiro que a chave existe para impedir.

Afrouxar a durabilidade (`innodb_flush_log_at_trx_commit = 2`) tiraria a maior
parte desse tempo, trocando por até um segundo de pagamentos confirmados ao
cliente e perdidos numa queda do servidor. Para uma escrita de dinheiro é a
troca errada, e ajuste de configuração do MySQL é assunto do bloco de
[pendências](#melhorias-que-ficariam-para-produção), com medição própria — não
de um commit de funcionalidade.

---

## Cache dos totalizadores

Os totalizadores são a parte cara do relatório: somar juros exige calcular
`POW` para cada linha do conjunto filtrado, e nenhum índice dispensa a conta. A
partir daqui eles ficam em cache por recorte — e a maior parte do trabalho não
foi fazer o cache acertar, foi garantir que ele **nunca sirva um número velho**.

### Antes: 12 segundos, e não 6

A medição da etapa 1 registrou 6,2 s para o recorte de um ano. Medido de novo
antes deste commit, no mesmo recorte (vencimento em 2026, 519.986 cobranças), com
a máquina parada: **12,1 a 14,0 s**. Consulta a consulta, pelo query log:

| Consulta | Tempo |
|---|---|
| agregação dos totalizadores | **8,2 – 8,5 s** |
| página de 25 linhas | 2,3 – 2,7 s |
| `COUNT` da paginação | 0,35 s |
| clientes da página | 1 – 2 ms |

Parte do crescimento tem autor e número: os [dígitos de
guarda](#três-armadilhas-que-o-desenho-precisou-resolver) que fizeram as duas
faces concordarem no meio centavo. Um A/B da agregação direto no MySQL, mesma
consulta com e sem o `CAST(... AS DECIMAL(20, 6))`:

| Expressão | Tempo | Soma atualizada |
|---|---|---|
| com o `CAST` (a atual) | 4,33 – 4,54 s | 2.832.396.064,22 |
| sem o `CAST` | 2,84 – 3,17 s | 2.832.396.063,9201 |

O `CAST` custa uns 40% da agregação, e fica: as somas diferem exatamente nos
centavos que ele existe para acertar. O resto da distância — 4,4 s no SQL direto
contra 8,3 s pela classe do relatório — não está explicado aqui. A diferença
visível é que a classe manda as datas como parâmetro vinculado, e o SQL direto
as mandou literais, o que pode mudar o plano. É exatamente o que o comando de
`EXPLAIN` do commit seguinte existe para mostrar.

### Depois

| Chamada, recorte de um ano | Tempo |
|---|---|
| primeira, sem cache | 12,9 s |
| seguintes, com cache | **2,9 – 3,3 s** |
| ordenada por valor atualizado, com cache | 3,9 s |
| primeira depois de uma escrita | 12,0 s |

Com cache, a agregação some do query log: no lugar dela entram a leitura da
versão dos dados (1,1 ms) e a do cache (1,7 ms). O que sobra são os 2,2 s da
página e os 0,35 s do `COUNT` — o cache não toca as linhas, e o próximo gargalo
do recorte de um ano é a consulta da página.

### O que invalida

Os totais guardados valem para três coisas ao mesmo tempo, e qualquer uma que
mude faz a próxima consulta recalcular:

- **A versão dos dados.** Um contador numa tabela de uma linha só, que sobe a
  cada escrita em `billings`, dentro da transação da escrita. Cadastro, edição,
  pagamento, estorno e alteração pelo console sobem pelo observer do Eloquent;
  a importação, que grava em lote sem passar por ele, sobe na mesma transação de
  cada lote; o seeder de volume sobe ao terminar.
- **A data de referência.** Os juros mudam de um dia para o outro sem escrita
  nenhuma, e nenhuma invalidação por evento pegaria isso. A data guardada é a
  mesma que o SQL usa — o `InterestCalculator` passou a expô-la —, e não um
  `now()` paralelo que poderia virar o dia entre um e outro.
- **O recorte.** Período, base da data, cliente e status. Ordenação, direção e
  página ficam de fora: mudam quais linhas aparecem e em que ordem, não o
  conjunto. Reordenar a tela reaproveita os totais, que é o uso mais comum.

Cada uma dessas invalidações tem teste, e o CSV aproveita os totais que a tela
já calculou — o PDF também, pelo mesmo método.

### A primeira ideia tinha uma corrida

A versão começou derivada dos dados, sem escrita nenhuma: `MAX(id)` de
`billings`, que muda a cada cobrança criada, e `MAX(id)` de `billing_audits`,
que muda a cada alteração — e a entrada da trilha é gravada na mesma transação
da alteração desde o commit da auditoria. Custava 1 ms cada, sem trava e sem
tabela nova.

Ela foi descartada antes de virar código, porque o autoincremento entrega ids na
ordem de **alocação**, não na de **commit**. Duas alterações simultâneas: T1
recebe o id 100, T2 recebe o 101, e T2 faz commit primeiro. Um leitor vê
`MAX(id) = 101`, calcula sem a alteração de T1 e guarda. T1 faz commit — e o
`MAX(id)` continua 101. O total sem T1 seria servido até a próxima escrita. Com
commit custando 0,3 s neste ambiente, dois pagamentos ao mesmo tempo bastam.

### Uma linha travada, e o preço dela

O contador sobe **dentro** da transação da escrita. A linha fica travada até o
commit, duas escritas simultâneas sobem o número em fila, e a versão cresce na
ordem de commit — a corrida acima não tem como acontecer. Os dados novos e a
versão nova ficam visíveis no mesmo instante.

Subir o contador fora da transação, depois do commit, tiraria a fila e abriria
duas janelas: o intervalo entre o commit dos dados e o da versão, em que o cache
serve o total de antes, e o processo que morre entre um e outro, que deixa o
total velho valendo até o dia virar.

O preço é a fila: **toda escrita em cobrança passa por esta linha.** Para
pagamentos feitos por pessoas é imperceptível. Para escrita concorrente pesada —
várias importações grandes em paralelo — vira gargalo, e aí a resposta é outra:
tabela de agregados atualizada por evento. O `increment` do driver de cache em
banco foi considerado e ficou de fora: ele devolve `false` quando a chave não
existe, em vez de criá-la, e faz o próprio `SELECT ... FOR UPDATE` — a mesma
trava, com mais passos e escondida.

A outra garantia é de ordem, e está no código: a versão é lida **antes** de
calcular. Os totais guardados foram calculados sobre dados no mínimo tão novos
quanto a versão que os acompanha. Se uma escrita entrar no meio, a versão
corrente sobe e a entrada não é servida; o inverso — dado velho sob versão nova
— não tem por onde acontecer.

### Uma entrada por recorte, e não uma por versão

O driver de cache em banco só apaga uma entrada vencida quando alguém a lê. Com a
versão dentro da chave, cada escrita abandonaria uma linha na tabela de cache
para sempre. Por isso a chave é só o recorte, e o **valor** guarda
`{versão, data, totais}`: quando a versão ou a data não batem, a entrada é
recalculada e sobrescrita. A tabela cresce com o número de recortes
consultados, não com o número de escritas — 306 bytes por recorte, medido. A
validade de um dia só existe para o recorte que ninguém mais consulta.

O driver é o de banco, o default do projeto. Um Redis leria mais rápido, mas é
um quinto serviço fora da stack fixa do teste — e trocar o driver depois não
mexe na correção, que vem da tabela de versão, não do cache. Uma consulta sem
cache paga, além da agregação, a gravação da entrada: um commit a mais.

---

## O plano de execução como ferramenta

```bash
docker compose exec php php artisan report:explain --start=2026-01-01 --end=2026-12-31
make explain ARGS="--start=2026-01-01 --end=2026-12-31 --analyze"
```

As medições de índice da etapa 1 foram feitas colando consultas no cliente do
MySQL. O trabalho não era o problema: o problema é que **consulta colada à mão
envelhece sem avisar**. Ela continua explicando bem um SQL que o código já não
gera — e o `EXPLAIN` de uma consulta que não existe mais é pior do que nenhum,
porque parece informação.

O comando **não tem SQL escrito dentro dele.** Ele roda o mesmo caminho que a
API usa, escuta o que o Eloquent mandou para o banco e explica cada consulta
capturada. Se o relatório mudar, o comando muda junto. Foi assim que a
`Contagem da paginação` entrou na lista: ninguém a escreveu, ela sai do
`paginate()`, e é a única das quatro que não estava documentada.

Duas escolhas que o comando faz de propósito:

- **Não passa pelo cache dos totalizadores.** Chama a agregação direto, porque
  o cache é justamente o que a ferramenta não pode enxergar — senão a consulta
  mais cara do relatório desapareceria da ferramenta feita para olhá-la.
- **Recusa opção inválida em voz alta.** O objeto de filtros descarta valor fora
  da allowlist e cai no default, o que é a proteção certa para a API porque
  esses valores viram nome de coluna em SQL. Num diagnóstico, cair no default em
  silêncio faria alguém medir um recorte que não é o que pediu e concluir a
  coisa errada.

`--analyze` troca o `EXPLAIN` por `EXPLAIN ANALYZE`: o MySQL executa e devolve o
tempo real de cada operação. `--literals` explica o mesmo SQL duas vezes, com
parâmetro vinculado e com os valores embutidos.

### O que a primeira rodada encontrou

No recorte de um ano da base de 2.000.000, as quatro consultas do relatório:

| Consulta | `type` | Chave | Linhas estimadas | Extra |
|---|---|---|---|---|
| Contagem da paginação | `range` | `billings_status_due_date_index` | 221.013 | `Using index for skip scan` |
| Página do relatório | **`ALL`** | **nenhuma** | **1.989.515** | **`Using where; Using filesort`** |
| Clientes da página | — | (PK, 25 ids) | 25 | — |
| Totalizadores | `range` | `billings_due_date_index` | 994.757 | `Using index condition; Using MRR` |

**A hipótese do commit anterior caiu.** A medição do cache deixou aberta uma
pergunta: a aplicação manda as datas como parâmetro vinculado e a medição à mão
as mandou literais, o que poderia mudar o plano. Com `--literals`, os planos são
**idênticos** nas três consultas sobre `billings` — mesma chave, mesmas linhas
estimadas, mesmo `Extra`. A diferença de tempo entre as duas medições não vem
daí; vem do estado do buffer pool e da contenção da máquina, que neste ambiente
move o tempo da agregação de 4 s para 27 s com a suíte rodando ao lado.

**E apareceu outra coisa, que não estava sendo procurada:** a consulta da página
faz **varredura completa com filesort**, mesmo com `billings_due_date_index`
entre as candidatas. É o que explica os 2,2 a 3,2 s que sobraram depois do cache
dos totalizadores — o recorte de um ano é 26% da tabela, o `SELECT` pede a linha
inteira, e o otimizador conclui que varrer sai mais barato que 520 mil acessos
aleatórios à chave primária; aí ordena meio milhão de linhas em filesort para
devolver 25. Achado registrado, não corrigido neste commit: a correção é índice,
e índice tem medição própria.

### `--analyze`: onde o tempo vai, operação por operação

Com a máquina parada, no mesmo recorte de um ano. A leitura é de dentro para
fora — a operação mais interna acontece primeiro:

```
── Página do relatório · executada em 2.416 ms
-> Limit: 25 row(s)                                    (actual time=2811..2811 rows=25)
    -> Sort: due_date DESC, id, limit input to 25       (actual time=2811..2811 rows=25)
        -> Filter: due_date entre 01/01 e 31/12         (actual time=0.174..2576 rows=519986)
            -> Table scan on billings                   (actual time=0.169..2273 rows=2e+6)
```

Dois milhões de linhas lidas para entregar 25: a varredura sozinha custa
2.273 ms, o filtro deixa 519.986 e a ordenação é sobre esse meio milhão.

```
── Totalizadores · executada em 8.583 ms
-> Aggregate: sum(...), count(0)                       (actual time=9262..9262 rows=1)
    -> Index range scan using billings_due_date_index   (actual time=28..5776 rows=519986)
```

Aqui o índice é usado: 5.776 ms para percorrer as 519.986 linhas do recorte, e o
resto até 9.262 ms é a conta — `POW` e `CAST` por linha, que nenhum índice
dispensa.

```
── Contagem da paginação · executada em 362 ms
-> Aggregate: count(0)                                 (actual time=478..478 rows=1)
    -> Covering index skip scan on billings            (actual time=0.121..334 rows=519986)
```

Duas leituras a fazer neste último. A primeira é que 519.986 linhas em 334 ms
mostram o que um índice de cobertura faz: nenhuma volta à tabela. A segunda é que
a estimativa do otimizador para esse caminho era **221.013 linhas contra 519.986
reais** — errada por 2,4x, e ainda assim o caminho escolhido foi o mais barato
dos três.

Um aviso sobre os números do `--analyze`: a instrumentação cobra. As mesmas
consultas medidas sem ela deram 362, 2.416 e 8.583 ms, contra 478, 2.811 e
9.262 ms com ela. Serve para ver a forma e a proporção, não para cravar o tempo
absoluto.

### A contagem da paginação usa um índice que ninguém pediu

`billings_status_due_date_index` existe para o filtro de vencidas. A contagem
não filtra status nenhum, e o MySQL o usa mesmo assim, em **skip scan**: ele
percorre o índice uma vez por valor distinto da primeira coluna — `pending` e
`paid` — e dentro de cada um aproveita o range de `due_date`. Duas passadas por
um índice estreito custam menos que uma pelo índice de `due_date` largo, e a
estimativa de linhas cai de 994 mil para 221 mil.

---

## Rate limit no login

Era a única pendência da etapa 1 com uma desculpa em vez de um número:
*"escolher um limite que não deixe a própria suíte intermitente exige
cuidado"*. O cuidado está aqui, e são **duas contagens por minuto**, porque são
dois ataques diferentes e uma contagem só deixaria um passar:

| Contagem | Limite | Pega |
|---|---|---|
| e-mail + IP | 5 | força bruta contra uma conta |
| IP | 20 | varredura de e-mails, uma tentativa em cada |

O limite por credencial inclui o IP **de propósito**. Contar só por e-mail
deixaria qualquer pessoa trancar a conta de outra de fora, errando a senha cinco
vezes: negação de serviço disfarçada de segurança. O limite por IP é folgado em
relação ao outro pelo motivo oposto — escritório com IP único faz login legítimo
de várias pessoas, e o que se quer pegar ali passa das dezenas.

Três detalhes que o teste fixa:

- **Requisição sem e-mail nem senha não conta.** O limite é verificado depois da
  validação: payload incompleto não é tentativa de autenticação, e contá-lo
  deixaria um formulário com bug trancar o próprio usuário.
- **Login correto zera a contagem da conta, não a do IP.** Acertar a senha prova
  que aquela conta não está sob força bruta; não prova nada sobre o IP, porque
  quem varre e-mails pode ter acertado o próprio.
- **O 429 diz quanto falta**, no corpo e no cabeçalho `Retry-After`.

Medido contra a aplicação rodando: cinco tentativas erradas respondem 401, a
sexta responde `429` com `Retry-After: 56`.

### Por que não o middleware `throttle`

O `throttle` do Laravel resolve o caso comum, e este tem um pedaço a mais: o
login correto precisa **zerar** a contagem. Zerar exige a mesma chave que o
middleware usa, e essa chave é derivada do nome do limitador por dentro do
framework — depender dela é depender de detalhe de implementação. O limitador
próprio tem as três operações (perguntar, contar, zerar) num arquivo de
cinquenta linhas, e a mensagem em português sai de graça.

### E a suíte não ficou intermitente

Três coisas garantem isso, e vale dizer porque era a razão da pendência: o
limite é **por credencial**, então um teste que erra a senha de um usuário não
atrapalha os outros; o cache da suíte é o de memória, então cada teste começa
com a contagem limpa; e nenhum teste faz mais de duas tentativas seguidas na
mesma conta — os que testam o limite usam e-mails próprios.

---

## Log estruturado

Uma linha de log é um objeto JSON, em `stderr`:

```json
{"message":"login.falhou","level_name":"WARNING","context":{
  "user_id":null,"request_id":"5903634cb4dbf38c8aba4a1464858b4f",
  "method":"POST","path":"api/auth/login","ip":"172.20.0.1",
  "email":"descartavel@billing.test"}}
```

`stderr` e não arquivo porque é onde `docker compose logs` procura — e porque a
imagem oficial do php-fpm já liga `catch_workers_output` e aponta o `error_log`
para o descritor 2, então a linha escrita pelo worker chega ao log do container.
Conferido antes de escolher: arquivo dentro do container só serve a quem já está
dentro dele.

### O identificador atravessa a borda

Quem gera o identificador é o **nginx**, com `$request_id`, e a aplicação o
recebe, devolve no cabeçalho da resposta e o repete em toda linha de log. Se o
cliente já mandou um `X-Request-Id`, ele é preservado: quem correlaciona
chamadas entre serviços é quem está mais acima na cadeia, e sobrescrever
quebraria a ligação.

O log de acesso do nginx imprime o mesmo id, e é isso que faz as duas pontas se
encontrarem:

```
nginx   req_id=caf4414de833cec75d085738f392eab2 rt=0.024
laravel {"message":"login.bloqueado", …, "request_id":"caf4414de833cec75d085738f392eab2", "retry_after":56}
```

### Um processador, e não `Log::withContext()`

O contexto é montado por um processador do Monolog, avaliado no momento em que
cada linha é escrita. A alternativa óbvia era um middleware chamando
`Log::withContext()`, e ela tem um defeito que só apareceria em produção:
**middleware de grupo roda antes do `auth:sanctum`**, então ali o usuário ainda
não existe e o `user_id` sairia nulo — enquanto no teste, onde `actingAs`
resolve o usuário mais cedo, pareceria funcionar. Teste verde pelo motivo
errado.

O processador também pergunta `hasUser()` antes de `id()`: pedir o id resolveria
o guard a partir do logger, invertendo a ordem das coisas. Linha escrita antes da
autenticação — uma tentativa de login falha — sai sem usuário, que é a verdade.

E houve um segundo engano no caminho, pego pelo próprio teste: a guarda dos
campos de requisição era `runningInConsole()`. Parece a pergunta certa e não é —
**a suíte roda pelo artisan**, ou seja, em console, então o teste jamais veria o
contexto que a produção vê. A guarda passou a ser a presença do cabeçalho de
identificador, que só existe quando o middleware passou.

### O que vai para o log, e o que não vai

O log responde "o que aconteceu nesta requisição". O que aconteceu com o **dado**
é a [trilha de auditoria](#trilha-de-auditoria), que é tabela e não texto.

Do login vão as três transições que interessam a quem investiga: `login.falhou`,
`login.bloqueado` e `login.ok`. As duas primeiras levam o **e-mail tentado** —
sem ele não há como distinguir alguém que errou a senha de uma varredura de
contas, que é exatamente a pergunta que se faz. É dado pessoal num log, e a
troca fica registrada aqui: o benefício é investigar tentativa de invasão em
massa, o custo é o e-mail no log de operação.

---

## Health check

```
GET /api/health

{"status":"ok","checks":{"database":{"ok":true,"duration_ms":10.05},
                         "cache":{"ok":true,"duration_ms":6.06}}}
```

Pública, porque sonda de monitoramento não faz login. Responde **503** com
`status: degraded` quando alguma dependência falha, dizendo qual e com a
mensagem do erro. Um health que responde 200 sempre é pior que nenhum: o
monitoramento passa a confiar nele e para de avisar.

A checagem do cache é de **leitura**. Escrever provaria mais e custaria um commit
por sonda — com o driver de banco, cada gravação vai ao disco, e um monitoramento
de dez em dez segundos escreveria 8.640 vezes por dia para responder uma pergunta
que a leitura já responde: o driver está acessível.

O `/up` do Laravel continua existindo e responde outra pergunta — se o PHP subiu.
Esta rota responde se as dependências respondem.

Um detalhe da documentação: o linter da spec avisa que a operação não declara
nenhum 4xx. Não declara porque não há — a rota é pública e não recebe entrada.
Inventar um 4xx para calar o aviso seria documentar o que não existe, então o
aviso fica.

---

## Página pública

A raiz atende duas plateias. **Com sessão**, `/` é o dashboard, protegido como
sempre. **Sem sessão**, ela mostra a apresentação do sistema em vez de empurrar
para o login — quem chega pela primeira vez precisa saber o que é isto antes de
ver um formulário de senha.

O middleware faz isso com **`rewrite`, não `redirect`**, e a diferença importa:
o endereço continua `/`. Um redirect para `/apresentacao` mudaria a URL na barra
e faria o botão "voltar" do browser brigar com o login.

A tabela de roteamento, verificada:

| Rota | Sem sessão | Com sessão |
|---|---|---|
| `/` | 200, apresentação | 200, dashboard |
| `/apresentacao` | 200 | 200 — é página pública |
| `/login` | 200 | 307 para `/` |
| `/clientes`, `/relatorio`, … | 307 para `/login?redirect=…` | 200 |

### Nenhum número da página é inventado

A skill de copywriting é explícita sobre estatística fabricada, e aqui a regra é
fácil de seguir porque a prova existe: não há depoimento de cliente nem logotipo
de empresa, porque não há cliente nem empresa. O que a página afirma é o que foi
medido — 2.000.000 de cobranças na base, 0,24s no recorte de um mês por cliente,
0,84s para o painel, 288 testes — 278 no backend e 10 de ponta a ponta.

A figura da dobra é o mesmo caso. Ela mostra uma cobrança de R$ 1.000,00 a 2% ao
mês virando **R$ 1.061,21** em 90 dias, e os sete pontos da curva foram gerados
pelo `InterestCalculator` do próprio sistema, não desenhados a olho. Inventar a
curva seria mentir sobre a única coisa que a página tem para provar.

Também não há imagem gerada: a skill de landing page sugere hero com foto de
pessoa satisfeita, e uma curva de juros real diz mais sobre este produto do que
um banco de imagens diria — além de não acrescentar megabytes de binário ao
repositório.

---

## Fundação visual

Os tokens e os primitivos vivem em [`app/globals.css`](frontend/app/globals.css)
e [`components/ui/`](frontend/components/ui/).

**A direção é a de um livro-razão**: papel e tinta, régua em fio de cabelo no
lugar de sombra, e número tabular em toda coluna de dinheiro. Não é gosto — é a
forma que o domínio já tem. Quem confere cobrança lê coluna de valor, e coluna
de valor só se lê alinhada: com algarismo de largura variável, `1.111,11` ocupa
menos espaço que `8.888,88` e a comparação de relance se perde. Por isso o
primitivo de tabela tem uma coluna `numeric` que é monoespaçada, tabular e
alinhada à direita, em vez de deixar a decisão para cada tela.

### Tema escuro sem uma única classe `dark:`

Os tokens são **semânticos** — `--color-ink`, não `--color-slate-900` — e cada
um declara os dois temas de uma vez:

```css
--color-paper: light-dark(#faf8f4, #121214);
--color-overdue: light-dark(#9d2b1e, #e58a7c);
```

`light-dark()` resolve pelo `color-scheme` do elemento, então trocar de tema é
trocar uma propriedade no `<html>` — e nenhum componente precisa repetir cada
cor com o prefixo `dark:`. O default segue o sistema operacional; `data-theme`
com `light` ou `dark` sobrepõe, e os dois seletores já existem para que um
futuro seletor de tema seja um atributo, e não uma reescrita da tabela de cores.

A primeira versão deste arquivo fazia o caminho comum: repetia o bloco inteiro
de tokens num `@media (prefers-color-scheme: dark)` e de novo num
`[data-theme="dark"]`. Um valor saiu digitado errado na segunda cópia — que é
exatamente o defeito que duplicar tabela de cor produz, e o argumento para não
duplicar.

### Nenhuma biblioteca de componentes

Sem shadcn/ui, sem Radix, sem Headless UI, sem Material. Os cinco primitivos —
botão, campo, card, etiqueta e tabela — somam pouco mais de 300 linhas, e o que
eles fazem é justamente o que uma biblioteca genérica não faria: a tabela sabe
o que é coluna de dinheiro, a etiqueta conhece os três estados do domínio, e o
botão trata `disabled` com fundo rebaixado em vez de opacidade.

Não há aqui nada que peça o que essas bibliotecas resolvem bem — combobox
acessível, diálogo com armadilha de foco, menu com navegação por teclado. A
tela mais complexa deste projeto é uma tabela com filtros. Trazer Radix para
isso seria adicionar uma dependência, um estilo a sobrescrever e uma camada de
API para aprender, em troca de nada que o HTML nativo não entregue.

O que existe de acessibilidade foi escrito à mão porque é onde ela costuma se
perder: o `Field` amarra `label`, `id`, `aria-invalid` e `role="alert"` numa
vez só, e o foco visível usa `:focus-visible` — o anel aparece para quem navega
por Tab e some para quem clica.

### O seletor de tema é um formulário HTML

Três estados — sistema, claro, escuro — e "sistema" é uma escolha própria: sem
ele, quem prefere acompanhar o sistema operacional não teria como voltar depois
de tocar no seletor uma vez.

A preferência vai para um cookie e é **lida no layout raiz, no servidor**. É o
que elimina o piscar: com `localStorage` a página renderiza no tema errado e
troca depois da hidratação, e a saída comum para isso é um script inline no
`<head>` que o bundler não enxerga. Lendo o cookie no servidor, o `<html>` já
sai da primeira resposta com `data-theme` correto.

**O seletor posta para um Route Handler, não para uma Server Action** — e isso
contraria a regra geral deste projeto, de que mutação usa Action. A exceção
está na própria regra: Route Handler é para o que o browser precisa *navegar*.

A versão com Server Action foi escrita primeiro e não funciona aqui. O cookie
era gravado e o servidor já respondia o tema novo, mas a tela continuava no
tema antigo até alguém recarregar: numa atualização suave o React não
reconcilia atributo do elemento `<html>`. Com `<form method="post">` o browser
navega de verdade, o layout raiz roda no servidor e o `<html>` chega pronto —
e o seletor passa a funcionar **sem JavaScript nenhum**.

A primeira versão do handler também caiu numa armadilha que este projeto já
tinha documentado em outro lugar: `NextResponse.redirect()` exige URL absoluta,
e dentro do container `request.nextUrl.origin` resolve para o endereço de bind
(`http://0.0.0.0:3000`), não para o host que o browser usou. O browser seguia
para **outra origem**, não mandava o cookie de sessão junto, e o usuário caía no
login a cada troca de tema. O `Location` agora é relativo, e o `Referer` só é
aceito se o host bater com o header `Host` — que é o host que o browser usou de
fato, e não o que o container acha que é.

### Tipografia

| | Família | Papel |
|---|---|---|
| Título | Instrument Serif | dá cara ao produto |
| Interface | IBM Plex Sans | humanista, boa em leitura densa |
| Dado | IBM Plex Mono | id, documento e dinheiro alinhados |

Servidas por `next/font`, que baixa e hospeda no build: sem requisição a
terceiro em runtime e sem salto de layout ao carregar.

---

## Decisões técnicas

**Comentário responde POR QUE, nunca O QUE.** O código diz o que faz; quem lê
consegue ler. O que não se recupera lendo é a alternativa que foi descartada, o
número que decidiu um limite, ou a armadilha que já custou uma tarde. Por isso
os comentários deste repositório são longos onde a decisão foi difícil —
`InterestCalculator`, a migration dos índices, o teto do PDF — e ausentes onde o
código é óbvio.

A varredura que fechou a etapa passou por todos os comentários do backend, do
frontend e dos testes. O que saiu foi boilerplate do skeleton do Laravel, que
repetia a assinatura do método em inglês:

```php
/**
 * Run the migrations.          <- o método se chama up()
 */
/**
 * Define the model's default state.    <- o método se chama definition()
 */
```

Junto saíram os `//` de corpo vazio e um `use` comentado que o skeleton deixa no
`User`. Cinquenta e uma linhas, nenhuma delas com informação.

Um comentário não foi removido, foi **corrigido**, e ele valia mais que todos os
outros juntos: a migration de `billings` dizia que "vencida" se deriva com
`due_date < CURDATE()`. É exatamente a função que a arquitetura deste projeto
proíbe — a data de referência desce do PHP, senão o teste de consistência nunca
fecha. Comentário errado é pior que comentário verboso: o verboso se ignora, o
errado se acredita.

**Nginx na frente do PHP-FPM, em vez de `artisan serve`.** O servidor embutido
do Laravel é single-threaded e não representa nada do comportamento real sob
carga. Como o projeto tem requisito explícito de exportação em streaming, o
`default.conf` desliga `fastcgi_buffering` — com o buffer ligado o nginx
seguraria o CSV inteiro antes de mandar a primeira linha, anulando o
`StreamedResponse`.

**Dockerfile do frontend em multi-stage, com o Compose usando o target `dev`.**
Os quatro stages são `deps` (npm ci), `dev` (HMR, usado pelo Compose), `build`
(gera o bundle) e `runner` (imagem de produção). O `runner` depende de
`output: "standalone"` no `next.config.ts`, que emite um `server.js` com apenas
as dependências realmente usadas. O target de produção existe e é construível,
mas não é o que o Compose sobe.

**`node_modules` e `.next` em volume anônimo.** O bind mount `./frontend:/app`
esconderia os do container, e os binários nativos (`@next/swc`,
`lightningcss`) compilados para o host não são os do Alpine.

**Porta do MySQL não publicada.** Nada no critério de aceite precisa dela, e
publicá-la é a forma mais fácil de colidir com um MySQL já rodando na máquina
de quem avalia. Para inspecionar o banco:

```bash
docker compose exec mysql mysql -u faturamento -psecret faturamento
```

**Tailwind CSS no frontend.** Veio no scaffold padrão do `create-next-app`. A
interface não precisa de design avançado, mas precisa ser responsiva e
componentizada, e o utilitário resolve isso sem introduzir uma biblioteca de
componentes que não foi pedida.

**Sem rate limit no login.** Deixado de fora de propósito nesta entrega: o
`throttle` do Laravel resolveria, mas escolher um limite que não deixe a
própria suíte intermitente exige cuidado que não agrega ao que o teste avalia.
Fica registrado como melhoria de produção, junto das demais.

**`UserResource` em vez de devolver o model.** Define desde já o formato da
resposta e evita que um campo novo na tabela vaze para a API sem alguém
decidir. Mesmo padrão que clientes e cobranças vão seguir.

**Credenciais em claro no `docker-compose.yml` e no `.env.example`.** É um
ambiente de avaliação local, e o critério de aceite exige que subir não dependa
de preencher segredo nenhum. Os valores do serviço `mysql` espelham os de
`backend/.env.example`; mudar um exige mudar o outro.

---

## Melhorias que ficariam para produção

Nenhuma foi aplicada na etapa 1: estão fora do que o teste pede, e
implementá-las aumentaria a superfície sem pontuar. A etapa 2 vai fechando os
itens um a um, e cada item fechado sai daqui ou fica só com o que resta dele. Ficam registradas porque são as que a
medição deste projeto realmente indica, não uma lista genérica.

**Dimensionar o `innodb_buffer_pool_size`.** É a primeira e a mais barata. A
tabela tem 177 MB de dados e 322 MB de índices contra um pool de 128 MB no
default — nada cabe, e toda varredura vai ao disco. Foi o que derrubou a
inserção do seeder de ~1.900 para ~150 linhas por segundo na segunda metade da
carga.

**Materializar os totalizadores.** O cache por recorte [foi feito na etapa
2](#cache-dos-totalizadores) e leva o recorte de um ano de 12,9 s para cerca de
3 s nas consultas seguintes. Mas a primeira consulta de cada recorte, e a
primeira depois de cada escrita, ainda pagam a agregação inteira — que é O(n)
por natureza. Uma tabela de agregados atualizada por evento de cobrança tiraria
esse custo também, com um cuidado que o cache não tem: os juros do que está
pendente mudam com o dia, então a parte pendente precisaria de recálculo diário.

**Particionar `billings` por data.** Com o relatório sempre recortando por
período, partições por ano ou trimestre tornariam a varredura de um recorte
largo proporcional ao recorte, e não à tabela.

**Índice FULLTEXT em `description`.** A busca usa `LIKE '%termo%'`, que não é
indexável por ter curinga à esquerda. Aceitável na tela de CRUD, não numa base
que cresce.

**Ler linhas cruas na exportação CSV.** Medido: dos 55s de uma exportação de
56.680 linhas, ~18s são banco e o resto é hidratar model Eloquent e instanciar
Carbon. `DB::table()` com join troca a conveniência do domínio por velocidade.

**Trocar o renderizador de PDF se volume for requisito.** `FPDF` ou `TCPDF`
emitem páginas incrementalmente e não montam a árvore inteira, o que removeria
o teto. Custa estilização mais trabalhosa — a troca certa quando o volume manda.

**Exportação assíncrona.** Acima de certo tamanho, gerar em fila e notificar o
usuário com um link, em vez de segurar uma conexão HTTP por minutos.

**Réplica de leitura para o relatório.** Consultas analíticas competindo com a
escrita transacional é o próximo gargalo depois do buffer pool.

**Observabilidade.** Log de consultas lentas com o plano de execução — os três
achados de performance deste projeto vieram de `EXPLAIN` rodado à mão, e isso
não escala como prática.

---

## Uso de inteligência artificial

O desenvolvimento foi conduzido com **Claude Code**. Os arquivos que orientam o
agente estão no repositório, como o teste exige:

| Arquivo | Papel |
|---|---|
| `CLAUDE.md` | Instruções de projeto: stack, a regra que governa a arquitetura, as duas origens de API, autenticação, limites de performance, ordem de commits |
| `.claude/skills/laravel-report-tests/SKILL.md` | Skill acionada em tarefa de teste, com as armadilhas específicas deste projeto |
| `.claude/skills/agent-browser/SKILL.md` | Automação de browser: navegar as telas, tirar screenshot e iterar sobre o que se está construindo |
| `.claude/skills/github-actions-docs/SKILL.md` | Sintaxe de workflow do GitHub Actions ancorada na documentação oficial, em vez de memória |
| `.claude/skills/vulnerability-scanner/SKILL.md` | Roteiro de análise de vulnerabilidade — OWASP, cadeia de suprimentos, superfície de ataque |
| `.claude/skills/crafting-effective-readmes/SKILL.md` | Como escrever README por público: contribuidor, avaliador, o próprio autor daqui a um ano |
| `.claude/skills/find-skills/SKILL.md` | Descoberta e instalação de skills do ecossistema aberto |
| `skills-lock.json` | Origem e hash de cada skill instalada do ecossistema |

As três do meio vieram prontas de outro projeto e foram copiadas sem alteração:
skill é conteúdo versionado, e reescrever uma na importação é perder a versão
que já foi exercitada em outro lugar.

As duas últimas vêm de repositórios públicos — `softaworks/agent-toolkit` e
`vercel-labs/skills` — e por isso existe o `skills-lock.json`, que grava a
origem e o hash do conteúdo de cada uma. É o mesmo motivo de um `composer.lock`:
uma dependência sem versão fixada não é uma dependência, é uma aposta. A
diferença é que aqui ela entra no contexto de quem escreve o código.

### O que a configuração efetivamente evitou

Vale mais mostrar onde ela mudou o resultado do que descrevê-la:

- **Tempo congelado.** A skill exige `travelTo()` em todo teste que toca juros.
  Sem isso, "vencida há 30 dias" mudaria de significado a cada dia e a suíte
  passaria a falhar sozinha.
- **`streamedContent()`.** A skill avisa que `assertSee` e `getContent()` não
  funcionam em `StreamedResponse`. Os testes de CSV nasceram certos.
- **Nada sobre o binário do PDF.** A skill delimita o que é verificável —
  status, content-type, e sobretudo o teto.
- **Totalizadores contra a página.** A skill descreve exatamente o erro fácil:
  montar cenário com mais registros do que cabe numa página e afirmar que os
  totais cobrem o conjunto. O teste existe nessa forma.
- **Teste antes do código.** Em toda etapa de regra de negócio o teste foi
  escrito primeiro e visto falhar. Foi o que fez o `InterestCalculator` nascer
  com o teste de consistência entre as duas faces, que é o teste mais
  importante do projeto.
- **Fonte única da regra.** O state `paidLate()` da factory ficou
  deliberadamente incompleto por duas etapas, em vez de repetir a fórmula de
  juros, até o `RegisterPayment` existir para preenchê-lo.

### Onde as instruções estavam erradas

Isto importa tanto quanto o resto: instrução de agente não é verdade revelada,
e duas delas não sobreviveram ao contato com a medição.

- **O teto do PDF era 5.000.** Os testes passavam, porque testes usam poucas
  linhas. A exportação contra a base real estourou a memória com 3.577. A curva
  medida mostrou que 5.000 precisaria de mais de 3 GB. O teto virou 1.000, e a
  medição ficou registrada ao lado do valor.
- **O nome do serviço `backend`.** O `CLAUDE.md` fixa
  `API_URL_INTERNAL=http://backend`, e o plano de infra chamava de `backend` o
  php-fpm — que fala FastCGI, não HTTP. Todo fetch de Server Component
  falharia, e só dentro do Docker. O nginx passou a se chamar `backend` e o
  `CLAUDE.md` ganhou a nota de que o nome é load-bearing.

Ambas as correções voltaram para o `CLAUDE.md`, que é o ponto: a configuração é
mantida junto do código e corrigida quando o código prova que ela está errada.

---

# Teste Técnico — Desenvolvedor Fullstack

## Objetivo

Desenvolver uma aplicação simples de faturamento com autenticação e um módulo de relatórios preparado para trabalhar com grandes volumes de dados.

O objetivo do teste é avaliar organização de código, modelagem de banco de dados, performance, domínio de backend e frontend, aplicação de regras de negócio e capacidade de justificar decisões técnicas.

---

## Tecnologias obrigatórias

### Backend

* PHP
* Laravel

### Frontend

* React ou Next.js
* TypeScript

### Banco de dados

* MySQL

### Infraestrutura

* Docker
* Docker Compose

---

## Contexto do projeto

A aplicação será utilizada para registrar cobranças realizadas para clientes.

Cada cobrança deverá possuir informações como:

* Cliente
* Descrição
* Valor original
* Data de emissão
* Data de vencimento
* Data de pagamento
* Status
* Taxa de juros
* Valor final atualizado

O sistema deverá possuir autenticação. Apenas usuários autenticados poderão acessar os registros e os relatórios.

---

## Funcionalidades obrigatórias

### 1. Autenticação

O sistema deverá permitir:

* Login
* Logout
* Proteção das rotas do sistema
* Proteção dos endpoints da API

Não é necessário desenvolver cadastro público de usuários.

---

### 2. Gestão de clientes

O sistema deverá permitir:

* Cadastrar clientes
* Editar clientes
* Listar clientes
* Visualizar os dados de um cliente

Dados mínimos do cliente:

* Nome
* Documento
* E-mail
* Status

---

### 3. Gestão de cobranças

O sistema deverá permitir:

* Cadastrar cobranças
* Editar cobranças
* Listar cobranças
* Visualizar uma cobrança
* Registrar o pagamento de uma cobrança

Dados mínimos da cobrança:

* Cliente
* Descrição
* Valor original
* Data de emissão
* Data de vencimento
* Data de pagamento
* Taxa de juros mensal
* Status

---

## Regra de negócio — cálculo de juros

Quando uma cobrança estiver vencida e ainda não estiver paga, o sistema deverá calcular seu valor atualizado em tempo real.

O cálculo deverá considerar:

* Valor original
* Taxa de juros mensal da cobrança
* Quantidade de dias em atraso
* Data atual

O candidato poderá escolher entre juros simples ou juros compostos, desde que:

* A regra utilizada esteja documentada
* O cálculo seja realizado no backend
* O resultado seja consistente em todas as telas e relatórios
* O valor calculado não precise obrigatoriamente ser salvo no banco de dados

Exemplo utilizando juros compostos:

```text
valor_atualizado = valor_original × (1 + taxa_mensal) ^ (dias_em_atraso / 30)
```

Ao registrar o pagamento, o sistema deverá armazenar:

* Data do pagamento
* Valor efetivamente pago
* Valor dos juros no momento do pagamento

---

## Módulo de relatórios

Desenvolver um relatório de faturamento por período.

O relatório deverá permitir filtros por:

* Data inicial
* Data final
* Cliente
* Status da cobrança

O usuário deverá conseguir escolher se o período será baseado em:

* Data de emissão
* Data de vencimento
* Data de pagamento

O relatório deverá exibir:

* Cliente
* Descrição da cobrança
* Data de emissão
* Data de vencimento
* Status
* Valor original
* Juros calculados
* Valor atualizado
* Valor pago

Também deverão ser exibidos totalizadores:

* Quantidade de cobranças
* Valor original total
* Total de juros
* Valor atualizado total
* Valor total recebido
* Valor total pendente

---

## Exportação dos relatórios

O relatório deverá poder ser exportado nos seguintes formatos:

* PDF
* CSV

As exportações deverão respeitar os filtros aplicados pelo usuário.

O arquivo exportado deverá conter:

* Período selecionado
* Filtros utilizados
* Dados do relatório
* Totalizadores

A solução adotada para geração dos relatórios deverá ser definida pelo candidato.

---

## Requisitos de performance

O módulo de relatórios deverá ser projetado considerando tabelas com milhões de registros.

A aplicação não precisa incluir milhões de registros no repositório, mas deverá possuir uma forma de gerar dados para testes.

Requisitos obrigatórios:

* Paginação realizada no backend
* Filtros realizados no banco de dados
* Ordenação realizada no backend
* Não carregar todos os registros em memória
* Evitar consultas N+1
* Criar índices adequados no banco de dados
* Utilizar migrations
* Disponibilizar factories ou seeders para gerar um volume significativo de dados
* Garantir que a exportação dos relatórios seja preparada para grandes volumes

O candidato deverá explicar no README:

* Quais índices foram criados
* Por que esses índices foram escolhidos
* Como o relatório se comportaria com milhões de registros
* Como a exportação em PDF e CSV se comportaria com grandes volumes
* Quais melhorias adicionais poderiam ser aplicadas em produção

---

## Frontend

O frontend deverá possuir, no mínimo:

* Tela de login
* Listagem de clientes
* Cadastro e edição de clientes
* Listagem de cobranças
* Cadastro e edição de cobranças
* Tela do relatório de faturamento
* Filtros do relatório
* Paginação
* Ordenação
* Exportação em PDF
* Exportação em CSV
* Estados de carregamento
* Tratamento de erros
* Feedback de operações realizadas com sucesso

A interface não precisa possuir um design avançado, mas deverá ser organizada, responsiva e componentizada.

---

## API

A comunicação entre frontend e backend deverá ocorrer por API.

A API deverá possuir:

* Validação das requisições
* Respostas HTTP adequadas
* Tratamento de erros
* Autenticação
* Paginação
* Filtros
* Ordenação
* Geração de relatórios em PDF
* Geração de relatórios em CSV

A estrutura e o padrão dos endpoints ficam a critério do candidato.

---

## Dockerização

O projeto deverá ser completamente executável por Docker.

A estrutura deverá incluir, no mínimo:

* Serviço do backend
* Serviço do frontend
* Serviço do MySQL
* Arquivo `docker-compose.yml`
* Configurações necessárias para comunicação entre os serviços
* Persistência dos dados do banco
* Instruções para subir o ambiente

O projeto deverá poder ser iniciado com poucos comandos, sem necessidade de configurar manualmente PHP, Node.js ou MySQL na máquina local.

---

## Testes automatizados

O projeto deverá possuir testes automatizados no backend.

Cenários mínimos:

* Usuário não autenticado não acessa o relatório
* Usuário não autenticado não exporta relatórios
* Cálculo de juros para cobrança vencida
* Cobrança paga não continua acumulando juros
* Filtros do relatório
* Totalizadores do relatório
* Registro de pagamento
* Exportação do relatório em PDF
* Exportação do relatório em CSV

Testes no frontend serão considerados um diferencial.

---

## Uso de inteligência artificial

O uso de ferramentas de inteligência artificial durante o desenvolvimento é permitido, mas não obrigatório.

Caso sejam utilizadas ferramentas de IA, as configurações, instruções ou arquivos utilizados para orientar os agentes deverão ser mantidos dentro do repositório do projeto.

Também será avaliada a forma como o candidato utiliza e configura agentes de IA no processo de desenvolvimento.

Boas práticas no uso serão consideradas de forma positiva.

---

## Organização dos commits

O desenvolvimento deverá ser realizado com commits pequenos, semânticos e separados por responsabilidade.

Evite concentrar toda a implementação em poucos commits grandes.

Exemplos:

```text
feat: add authentication structure
feat: create customers module
feat: create billing module
feat: add overdue interest calculation
feat: create billing report filters
feat: add csv report export
feat: add pdf report export
test: add billing interest tests
chore: add docker environment
docs: update project instructions
```

Os commits também serão considerados durante a avaliação.

---

## Entrega

O candidato deverá realizar a entrega seguindo obrigatoriamente este fluxo:

1. Criar um **fork** do repositório disponibilizado para o teste.
2. Criar uma nova branch dentro do fork utilizando o próprio nome.

Exemplo:

```text
joao-silva
```

3. Desenvolver toda a solução nessa branch.
4. Manter o histórico de commits pequenos, semânticos e separados por responsabilidade.
5. Ao finalizar, abrir um **Pull Request da branch criada no fork para o repositório original do teste**.

Exemplo do fluxo:

```text
fork-do-candidato:joao-silva
    ↓
repositorio-original:main
```

O Pull Request deverá conter:

* Título claro e objetivo
* Resumo da solução desenvolvida
* Instruções para executar o projeto
* Instruções para executar os testes
* Explicação das decisões técnicas
* Explicação da estratégia de performance
* Explicação da geração dos relatórios
* Pontos que não foram concluídos, caso existam

O repositório deverá conter:

* Código do backend
* Código do frontend
* Dockerfiles
* Arquivo `docker-compose.yml`
* Migrations
* Factories e seeders
* Testes automatizados
* Arquivo `.env.example`
* Instruções para executar o projeto
* Instruções para executar os testes
* Explicação das decisões técnicas
* Explicação da estratégia de performance

Não serão aceitas entregas por arquivo compactado, e-mail, link para outro repositório ou qualquer outro meio externo.

A entrega deverá ser realizada exclusivamente por meio do Pull Request aberto a partir do fork do candidato para o repositório original disponibilizado para o teste.

---

## Critérios de avaliação

Serão avaliados:

* Organização e legibilidade do código
* Arquitetura da aplicação
* Modelagem do banco de dados
* Qualidade da API
* Componentização do frontend
* Uso correto do TypeScript
* Aplicação da regra de negócio
* Performance das consultas
* Estratégia de geração dos relatórios
* Segurança e autenticação
* Dockerização do projeto
* Qualidade dos testes automatizados
* Tratamento de erros
* Documentação
* Histórico de commits
* Qualidade e organização do Pull Request
* Configuração e uso de agentes de IA, caso utilizados

---

## Diferenciais

Serão considerados diferenciais:

* Testes no frontend
* Controle de acesso por perfil
* Documentação da API
* Uso de ferramentas de análise de consultas
* Estratégia para geração de relatórios muito grandes
* Cache de relatórios ou totalizadores
* Pipeline de integração contínua
* Monitoramento ou observabilidade
* Cobertura de testes documentada

---

## Prazo sugerido

Prazo de entrega sugerido: até 5 dias corridos.

O teste foi planejado para exigir aproximadamente 8 a 12 horas de desenvolvimento.

Não é necessário implementar funcionalidades além das solicitadas. O foco deve estar na qualidade da solução, nas decisões técnicas e na clareza da implementação.
