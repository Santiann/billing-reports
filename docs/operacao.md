# Operação: executar, observar, proteger

[← README](../README.md)

- [Como executar](#como-executar)
- [Documentação da API](#documentação-da-api)
- [Integração contínua](#integração-contínua)
- [Log estruturado](#log-estruturado)
- [Health check](#health-check)
- [Rate limit no login](#rate-limit-no-login)
- [Revisão de segurança](#revisão-de-segurança)

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
migrations terminam, o que na primeira subida
[demora](#quanto-demora-a-subida-do-zero):

```bash
docker compose up -d
docker compose exec php php artisan db:seed
```

Não há `.env` para copiar nem `composer install` para rodar à mão: o entrypoint
do backend resolve os dois (ver [Bootstrap automático](arquitetura.md#bootstrap-automático-do-backend)).

| Perfil | E-mail | Senha |
|---|---|---|
| Administrador | `admin@billing.test` | `password` |
| Consulta | `consulta@billing.test` | `password` |

O segundo usuário existe para o perfil de consulta poder ser visto funcionando
(ver [Perfis de acesso](modulos.md#perfis-de-acesso)).

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
Medido aqui: a espera durou 35 segundos com o datadir do MySQL já criado, e
perto de 1min45s numa instalação do zero, em que o entrypoint ainda roda o
`composer install` antes das migrations.

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

### Quanto demora a subida do zero

Medido nesta máquina (WSL2 com Docker Desktop) a partir de um clone novo da
branch — sem `vendor/`, sem `.env`, sem `node_modules`, sem imagem do projeto
e sem o volume do banco. Cada fase foi cronometrada separada.

| Fase | Tempo |
|---|---|
| `git clone --depth 1` | 5 s |
| Download das imagens base `php`, `node` e `nginx` | 25 s |
| Build das imagens `php` e `frontend` | 4min36s |
| Download da imagem `mysql:8.0` | 51 s |
| `make install` | **7min09s** |
| Primeira tela: `/login` compilado sob demanda pelo Next | 19 s |
| **Até a tela de login** | **13min25s** |
| `make seed-volume` — 2.000.000 de cobranças | 49min16s |
| **Até a base de medição carregada** | **1h02min42s** |

A carga de volume se divide em 2,9 s para derrubar os índices, cerca de 32
minutos de inserção e **16min27s** para recriá-los. Na medição do commit que
adiou os índices, a mesma estratégia levou 45min55s — esta saiu 7% mais lenta,
e as duas estão em [Índices adiados na carga](performance.md#índices-adiados-na-carga).

Dentro do `make install`, pelos horários dos logs de cada container:

| Etapa | Tempo |
|---|---|
| Rede, volume e os quatro containers criados, até o MySQL iniciar | 1min13s |
| MySQL: criação do datadir | 2min59s |
| MySQL: scripts de init — banco de teste e usuário da aplicação | 47 s |
| MySQL: reinício na porta 3306 até o healthcheck passar | 16 s |
| Entrypoint do php: `composer install`, 119 pacotes | 36 s |
| Entrypoint do php: `.env`, `APP_KEY` e as 12 migrations | 1min13s |
| Última sonda do `wait-migrations` e seeder base | 5 s |

Quem já tem a stack e roda `docker compose down -v && make install` na mesma
árvore pula o build e os downloads, e o entrypoint pula o `composer install`
porque `vendor/` já existe: pela tabela, algo perto de **7 minutos** até a
tela de login. Esse número é derivado, não medido separado.

**O que a medição não cobriu diretamente.** As imagens base não saíram do
cache, e por dois motivos diferentes. `php:8.3-fpm-alpine` e `node:22-alpine`
moram no cache do BuildKit, e `build --no-cache` ignora o cache de camadas,
não a imagem base — não baixa de novo. `nginx:1.27-alpine` está em uso por um
container de outro projeto nesta máquina e não foi apagada. Os 25 s vêm de uma
medição à parte: as camadas comprimidas das três (107 MB) baixadas direto do
registry, uma depois da outra, sem extração. Para comparar, o `mysql:8.0` são
222,8 MB comprimidos e levou 51 s já com a extração. Download é banda: aqui
variou de 3 a 7 MB/s.

**A fase que varia é a do MySQL.** A primeira medição do first-init, na etapa
1, levou **10min20s** entre `Initializing database files` e `ready for
connections` na porta 3306. Esta levou **3min52s** — mesma máquina, mesma
configuração do Compose. A imagem baixada agora é a 8.0.46; a versão da
primeira medição não ficou registrada, e não medi a causa da diferença. Nesse
intervalo o backend fica parado esperando o healthcheck, e é o comportamento
correto.

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
motor](testes.md#banco-de-testes).

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
é a [trilha de auditoria](modulos.md#trilha-de-auditoria), que é tabela e não texto.

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

## Revisão de segurança

Feita com a skill `vulnerability-scanner`, na ordem que ela propõe:
reconhecimento, descoberta, análise, relato. O que segue é o resultado completo
— o que foi corrigido **e** o que foi avaliado e descartado, com o motivo.

### Corrigido

| Achado | Por que importa | Correção |
|---|---|---|
| **CORS aberto** — o default do framework é `allowed_origins: ['*']` | A API aceita token no cabeçalho; origem `*` é superfície que este desenho não usa, porque o browser nunca chama a API direto | `config/cors.php` restrito ao frontend, com lista explícita de cabeçalhos aceitos e expostos |
| **Token Sanctum sem expiração** (`expiration => null`) | O cookie de sessão dura 8h, mas o token continuava válido para sempre — vazamento sem prazo de validade | 480 minutos, o mesmo prazo do cookie |
| **Nenhum cabeçalho de segurança** | Clickjacking, sniffing de tipo, vazamento de referrer | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` nas duas origens, mais CSP completo na API |
| **`X-Powered-By: PHP/8.3.33`** | Dizer a versão poupa a quem sonda o trabalho de descobrir qual CVE tentar | `expose_php = Off` |
| **Health vazava a mensagem do driver** | A rota é pública, e o erro do PDO nomeia host, porta e driver | Mensagem genérica na resposta, detalhe no log estruturado |
| **API sem teto de requisições** | O motivo aqui não é força bruta, é custo: uma consulta sem cache no recorte de um ano leva 12s de banco, e um laço derruba o serviço com credencial legítima | `throttle:api`, 180/min, contados **por usuário** |
| **`Str::markdown` renderizando HTML cru** | XSS teórico na página de documentação, cujo conteúdo vem da spec | `html_input => escape` |

O limite da API é contado por usuário, e não por IP, por um detalhe deste
desenho: o frontend chama a API pelo servidor do Next, então **todas** as
requisições da aplicação chegam do mesmo endereço. Contar por IP faria um
usuário ativo limitar todos os outros.

O CSP da API é o mais restritivo possível — `script-src 'none'` — e isso só é
viável porque a [página de documentação](#a-raiz-do-backend-é-a-documentação) é
montada no servidor e não tem um único `<script>`. A escolha de não usar
renderizador de spec do mercado, feita por outro motivo, pagou aqui também.

### Avaliado e descartado

**CSP na aplicação Next.** O servidor de desenvolvimento precisa de
`unsafe-eval` e estilo inline; uma política que valesse só em produção iria ao
ar sem nunca ter sido exercitada, e CSP que ninguém testou quebra a aplicação no
pior momento. Ficam os quatro cabeçalhos que valem nos dois ambientes.

**Dependências.** `composer audit` e `npm audit`: nenhum advisory. Os dois
lockfiles são versionados e o CI usa `npm ci`, que instala exatamente o
lockfile e falha se ele divergir do manifesto.

**Injeção de SQL.** Todo SQL cru do projeto vem de dois lugares: o
`InterestCalculator`, que gera a expressão com a data de referência vinda do
PHP, e as agregações do dashboard, que usam parâmetro vinculado. Coluna de
ordenação e base de data passam por **duas** allowlists — o `FormRequest` e o
objeto de filtros — justamente porque viram nome de coluna.

**Upload de CSV.** Validado em tipo e tamanho (20 MB), e o caminho lido é o do
arquivo temporário que o PHP criou, não um valor da requisição. O leitor é
streaming e falha com mensagem clara quando o cabeçalho não bate.

**Geração de PDF.** O dompdf vem com `enable_remote` e `enable_php`
desligados: sem SSRF por imagem remota e sem execução de PHP dentro do
template.

**Acesso a registro de outro usuário.** Qualquer usuário autenticado vê
qualquer cobrança. Não há conceito de cliente-dono nem de organização na
especificação, e inventá-lo seria escopo extra; o que existe é o [perfil de
consulta](modulos.md#perfis-de-acesso), que separa leitura de escrita. Fica registrado
como limite conhecido, não como descuido.

**`APP_DEBUG=true` e senhas de exemplo.** São o ambiente local que a especificação pede
— `docker compose up -d` tem que entregar a aplicação usável, com credenciais
documentadas. Em produção, `APP_DEBUG=false`, `APP_ENV=production` e segredos
fora do repositório são pré-requisito, não ajuste.

**Fixar as ações do CI por SHA em vez de major.** `actions/checkout@v7` confia
na tag, que é móvel. Fixar por SHA protege contra a tag ser reapontada, e é
prática de organização com requisito de assurance alto; para este projeto o
custo de manutenção não se paga.

### Sobre o número que o scanner reportou

O script da skill acusou **224 padrões perigosos, 23 críticos** — e nenhum é
nosso. Ele varre o diretório inteiro, e os achados estão em
`backend/vendor/phpunit/.../billboard.pkgd.min.js` e companhia: concatenação de
string em código minificado de terceiros. Rodado sobre o nosso código, o
resultado é outro:

```
backend/app         0 crítico, 0 alto
backend/routes      0 crítico, 0 alto
backend/config      0 crítico, 0 alto
frontend/app        0 crítico, 0 alto
frontend/components 0 crítico, 0 alto
frontend/lib        0 crítico, 0 alto
```

Registrar isso importa porque a leitura preguiçosa do relatório levaria à
conclusão oposta. Ferramenta que varre `vendor/` mede a internet, não o
projeto — e o achado de configuração dela, o dos cabeçalhos ausentes, era
verdadeiro e virou correção.

### Falhar fechado

A última categoria da OWASP 2025 é condição excepcional, e vale listar o que o
projeto faz quando algo dá errado:

- **Chave de idempotência em erro de servidor:** devolvida, não guardada — um
  500 não é resultado, e repetir é o certo.
- **Health com dependência fora:** 503, nunca 200 otimista.
- **Logout com a API fora do ar:** o cookie é apagado de qualquer forma. Deixar
  o usuário preso numa sessão que ele pediu para encerrar é pior que um token
  órfão, que agora expira sozinho.
- **Sem trilha de auditoria, sem alteração:** a gravação da cobrança e a da
  trilha estão na mesma transação.
