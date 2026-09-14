# Arquitetura e decisões

[← README](../README.md)

- [Serviços](#serviços)
- [Autenticação](#autenticação)
- [Modelagem](#modelagem)
- [Cálculo de juros](#cálculo-de-juros)
- [Decisões técnicas](#decisões-técnicas)

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
[rate limit no login](operacao.md#rate-limit-no-login).

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
