# Módulos e regras de negócio

[← README](../README.md)

- [Módulo de clientes](#módulo-de-clientes)
- [Módulo de cobranças](#módulo-de-cobranças)
- [Importação por CSV](#importação-por-csv)
- [Relatório de faturamento](#relatório-de-faturamento)
- [Perfis de acesso](#perfis-de-acesso)
- [Idempotência no pagamento](#idempotência-no-pagamento)
- [Trilha de auditoria](#trilha-de-auditoria)
- [Estorno de pagamento](#estorno-de-pagamento)

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
da carga. (Medido com a máquina ocupada. A remedição da etapa 2, com a máquina
parada e os oito índices de hoje, está em [índices adiados na
carga](performance.md#índices-adiados-na-carga).)

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
segundos por teste](testes.md#o-teste-do-seeder-não-emite-ddl).

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
nas duas faces. É a [regra que governa a arquitetura](arquitetura.md#cálculo-de-juros)
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
[pendências](producao.md#melhorias-que-ficariam-para-produção), com medição própria — não
de um commit de funcionalidade.
