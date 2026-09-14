# Performance: medições e índices

[← README](../README.md)

- [Dashboard](#dashboard)
- [Índices](#índices)
- [Cache dos totalizadores](#cache-dos-totalizadores)
- [O plano de execução como ferramenta](#o-plano-de-execução-como-ferramenta)
- [Exportação em CSV](#exportação-em-csv)
- [Exportação em PDF](#exportação-em-pdf)
- [Gerando volume para teste](#gerando-volume-para-teste)

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
guarda](arquitetura.md#três-armadilhas-que-o-desenho-precisou-resolver) que fizeram as duas
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
do que o tempo — descrito em [Testes](testes.md#o-teste-do-seeder-não-emite-ddl).

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
