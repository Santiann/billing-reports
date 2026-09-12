# Gerador de Relatórios de Faturamento

Aplicação de faturamento com autenticação e relatório de cobranças projetado
para tabelas na casa dos milhões de registros.

| | |
|---|---|
| **Backend** | PHP 8.3 + Laravel 13 (API REST) |
| **Frontend** | Next.js 16 (App Router) + TypeScript |
| **Banco** | MySQL 8 |
| **Infra** | Docker + Docker Compose |

O enunciado original do teste está preservado na íntegra [mais abaixo](#teste-técnico--desenvolvedor-fullstack).

| | |
|---|---|
| **Começar** | [Como executar](#como-executar) · [Makefile](#os-alvos-do-makefile) · [Serviços](#serviços) · [Gerando volume](#gerando-volume-para-teste) · [Testes](#testes) |
| **Domínio** | [Modelagem](#modelagem) · [Cálculo de juros](#cálculo-de-juros) · [Autenticação](#autenticação) · [API](#documentação-da-api) |
| **Módulos** | [Clientes](#módulo-de-clientes) · [Cobranças](#módulo-de-cobranças) · [Relatório](#relatório-de-faturamento) |
| **Performance** | [Índices](#índices) · [Exportação CSV](#exportação-em-csv) · [Exportação PDF](#exportação-em-pdf) |
| **Decisões** | [Fundação visual](#fundação-visual) · [Técnicas](#decisões-técnicas) · [Erro e carregamento](#estados-de-erro-e-carregamento) · [Produção](#melhorias-que-ficariam-para-produção) · [Uso de IA](#uso-de-inteligência-artificial) |

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

| Credencial | Valor |
|---|---|
| E-mail | `admin@billing.test` |
| Senha | `password` |

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
| `make lint` | `pint --test` no backend, `tsc --noEmit` e `eslint` no frontend |

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

Mitigações de produção, não aplicadas aqui por estarem fora do escopo do teste:
cache dos totalizadores por combinação de filtros, tabela de agregados
atualizada por evento, ou particionamento da tabela por data.

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
OK (153 tests, 582 assertions)
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

Nenhuma foi aplicada: estão fora do que o teste pede, e implementá-las
aumentaria a superfície sem pontuar. Ficam registradas porque são as que a
medição deste projeto realmente indica, não uma lista genérica.

**Dimensionar o `innodb_buffer_pool_size`.** É a primeira e a mais barata. A
tabela tem 177 MB de dados e 322 MB de índices contra um pool de 128 MB no
default — nada cabe, e toda varredura vai ao disco. Foi o que derrubou a
inserção do seeder de ~1.900 para ~150 linhas por segundo na segunda metade da
carga.

**Cachear ou materializar os totalizadores.** Eles são O(n) por natureza: somar
juros exige calcular `POW` para cada linha do conjunto filtrado, e nenhum
índice dispensa a conta. Um cache por combinação de filtros, ou uma tabela de
agregados atualizada por evento de cobrança, resolveria o recorte largo — que
é justamente onde o índice não ajuda.

**Particionar `billings` por data.** Com o relatório sempre recortando por
período, partições por ano ou trimestre tornariam a varredura de um recorte
largo proporcional ao recorte, e não à tabela.

**Índice FULLTEXT em `description`.** A busca usa `LIKE '%termo%'`, que não é
indexável por ter curinga à esquerda. Aceitável na tela de CRUD, não numa base
que cresce.

**Rate limit no login.** Deixado de fora porque escolher um limite que não
deixe a própria suíte intermitente exige cuidado que não agrega ao que o teste
avalia. Em produção é obrigatório.

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

As três últimas vieram prontas de outro projeto e foram copiadas sem alteração:
skill é conteúdo versionado, e reescrever uma na importação é perder a versão
que já foi exercitada em outro lugar.

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
