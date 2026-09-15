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

Todo número citado aqui foi **medido** contra uma base de 2.000.000 de cobranças
e 5.000 clientes, gerada pelo seeder do projeto. Cada medição está registrada
com o `EXPLAIN` ao lado em [docs/performance.md](docs/performance.md) — e o que
foi descartado está lá com o número que embasou o descarte.

---

## Como executar

Pré-requisito único: Docker com Compose v2.

```bash
git clone https://github.com/Santiann/billing-reports.git
cd billing-reports
git checkout joao-santian
make install
```

Medido a partir de um clone novo, sem nenhuma imagem baixada:

| Fase | Tempo |
|---|---|
| Clone, download das imagens e build | 5min57s |
| `make install` — primeiro boot do MySQL, dependências e migrations | 7min09s |
| Primeira tela, compilada sob demanda | 19 s |
| **Até a tela de login** | **13min25s** |
| `make seed-volume` — 2.000.000 de cobranças, só para medir | 49min16s |
| **Até a base de medição carregada** | **1h02min42s** |

A fase que mais varia é o primeiro boot do MySQL: nesta mesma máquina levou
3min52s nessa medição e 10min20s numa anterior. A tabela fase a fase, e o que
ficou fora dela, está em [docs/operacao.md](docs/operacao.md#quanto-demora-a-subida-do-zero).

Sem `make`: `docker compose up -d` e, depois das migrations,
`docker compose exec php php artisan db:seed`.

| | |
|---|---|
| Aplicação | http://localhost:3000 |
| API e documentação | http://localhost:8000 |
| Administrador | `admin@billing.test` / `password` |
| Perfil de consulta | `consulta@billing.test` / `password` |

Não há `.env` para copiar nem `composer install` para rodar: o entrypoint
instala dependências, cria o `.env`, gera a `APP_KEY` e roda as migrations.

Para gerar volume de medição: `make seed-volume`. Os onze alvos do Makefile e o
que cada um faz estão em [docs/operacao.md](docs/operacao.md#os-alvos-do-makefile).

## Como testar

```bash
make test     # 283 testes, 1.070 asserções · 97,44% de linhas cobertas
make e2e      # 10 testes de ponta a ponta (Playwright), contra a stack de pé
make lint     # Pint no backend; typegen, typecheck e ESLint no frontend
```

A suíte roda em **MySQL**, não em SQLite, e o motivo está na regra abaixo: em
SQLite o teste de consistência validaria outro motor — `POW()` nem existe por
padrão. O CI roda suíte e lint a cada push; os testes de ponta a ponta ficam
fora dele porque exigiriam subir a stack inteira no runner.

Detalhes, cobertura e as armadilhas que a suíte precisou resolver:
[docs/testes.md](docs/testes.md).

---

## A regra que governa a arquitetura

**O valor atualizado de uma cobrança vencida tem que ser calculável em SQL.**

O relatório ordena por valor atualizado e soma os juros do conjunto filtrado
**inteiro**. Se o cálculo existisse apenas em PHP, qualquer uma das duas
operações obrigaria a carregar o resultado todo em memória — o que o enunciado
proíbe explicitamente.

Daí decorre quase todo o resto do projeto:

- `InterestCalculator` é a **fonte única** da regra e tem duas faces: uma que
  gera SQL, usada na listagem e nas agregações, e uma em PHP, usada para exibir
  uma cobrança isolada. **Um teste afirma que as duas concordam até o centavo**
  — e ele é o teste mais importante do repositório.
- Juros compostos: `valor_original * (1 + taxa_mensal) ^ (dias_atraso / 30)`.
- A data de referência **desce do PHP**, nunca `CURDATE()`. É o que torna o
  cálculo testável com tempo congelado e o que permite calcular juros na data
  do pagamento.
- Cobrança paga **não acumula juros**: eles congelam no ato do pagamento, e o
  valor exibido vem das colunas gravadas.
- "Vencida" não é status gravado: é `pending` com vencimento no passado, e a
  mesma condição existe nas duas faces.

O desenho completo — modelagem, autenticação, as duas origens de API do Next,
nomeação dos serviços — está em [docs/arquitetura.md](docs/arquitetura.md).

---

## O que está entregue

**Obrigatório:** autenticação por token, cadastro de clientes e de cobranças,
cálculo de juros compostos sobre atraso, relatório por período com filtros,
ordenação e totalizadores resolvidos no banco, exportação em CSV e em PDF,
índices, Docker de um comando e suíte automatizada.

**Diferenciais**, cada um com a decisão registrada:

| | |
|---|---|
| [Dashboard](docs/performance.md#dashboard) | Indicadores do mês e série de doze meses, em duas agregações |
| [Importação por CSV](docs/modulos.md#importação-por-csv) | Clientes e cobranças, com prévia antes de gravar e erro linha a linha |
| [Perfis de acesso](docs/modulos.md#perfis-de-acesso) | Administrador e consulta, com a barreira no backend |
| [Idempotência no pagamento](docs/modulos.md#idempotência-no-pagamento) | Oito requisições simultâneas produzem um pagamento |
| [Trilha de auditoria](docs/modulos.md#trilha-de-auditoria) | Quem alterou o quê e quando, na mesma transação da alteração |
| [Estorno de pagamento](docs/modulos.md#estorno-de-pagamento) | Volta a pendente com os juros correndo desde o vencimento original |
| [Cache dos totalizadores](docs/performance.md#cache-dos-totalizadores) | Recorte de um ano de 12,9s para ~3s, sem nunca servir número velho |
| [`report:explain`](docs/performance.md#o-plano-de-execução-como-ferramenta) | Comando que imprime o plano das consultas do relatório |
| [Rate limit e log estruturado](docs/operacao.md#rate-limit-no-login) | Duas contagens no login; log em JSON com id que atravessa o nginx |
| [Health check](docs/operacao.md#health-check) | 503 honesto quando uma dependência cai |
| [CI](docs/operacao.md#integração-contínua) | Dois jobs paralelos: suíte e lint |
| [Testes de ponta a ponta](docs/testes.md#testes-de-ponta-a-ponta) | Playwright cobrindo login, cadastro, pagamento, exportação e 360px |
| [Revisão de segurança](docs/operacao.md#revisão-de-segurança) | Sete correções, e o registro do que foi descartado |
| [Fundação visual](docs/frontend.md#fundação-visual) e [página pública](docs/frontend.md#página-pública) | Tokens semânticos, tema claro e escuro, e nenhum número inventado |

---

## Números medidos

Base de 2.000.000 de cobranças e 5.000 clientes.

| | |
|---|---|
| Dashboard completo | **0,66 – 0,93 s** |
| Relatório, um mês por cliente | **0,24 s** |
| Relatório, recorte de um ano | **12,9 s** sem cache, **~3 s** com |
| Agregação dos totalizadores | 8,2 – 8,5 s |
| Leitura da trilha de uma cobrança | 0,127 ms |
| Teto do PDF | **1.000 linhas** — 420 MB para mil, mais de 3 GB para cinco mil |
| Custo de um commit neste ambiente | 183 – 360 ms |
| Carga dos 2.000.000 de registros | **46 – 49 min** com os índices adiados — 310 com eles presentes |

Três dessas medições **mudaram decisões já tomadas**: o teto do PDF caiu de
5.000 para 1.000, o recorte de um ano não levava 6 segundos e sim 12, e a
primeira ideia de invalidação do cache tinha uma corrida e foi descartada antes
de virar código. As três estão contadas em
[docs/performance.md](docs/performance.md).

---

## Onde está o resto

| Documento | O que tem |
|---|---|
| [docs/arquitetura.md](docs/arquitetura.md) | A regra dos juros em detalhe, modelagem, autenticação, serviços e as decisões técnicas transversais |
| [docs/modulos.md](docs/modulos.md) | Clientes, cobranças, importação, relatório, perfis, idempotência, trilha e estorno |
| [docs/performance.md](docs/performance.md) | Dashboard, índices, cache, `report:explain`, exportações e geração de volume — com os `EXPLAIN` |
| [docs/frontend.md](docs/frontend.md) | Fundação visual, página pública e estados de erro e carregamento |
| [docs/operacao.md](docs/operacao.md) | Executar, Makefile, documentação da API, CI, log, health, rate limit e segurança |
| [docs/testes.md](docs/testes.md) | Suíte, cobertura nomeada linha a linha e testes de ponta a ponta |
| [docs/ia.md](docs/ia.md) | Uso de IA: as skills, o que elas evitaram e onde as instruções estavam erradas |
| [docs/producao.md](docs/producao.md) | O que ficaria para produção, com número ao lado |
| [docs/enunciado.md](docs/enunciado.md) | O enunciado original do teste, preservado na íntegra |

## Uso de inteligência artificial

O desenvolvimento foi conduzido com **Claude Code**, e os arquivos que orientam
o agente estão versionados — `CLAUDE.md` e `.claude/skills/`, como o enunciado
pede. O que vale registrar não é a ferramenta, é onde a configuração mudou o
resultado: teste antes do código em toda regra de negócio, tempo congelado nos
testes de juros, e **duas instruções que não sobreviveram à medição** e voltaram
corrigidas para o `CLAUDE.md`.

A lista completa, com as skills usadas e o efeito de cada uma:
[docs/ia.md](docs/ia.md).

## O que ficaria para produção

Buffer pool dimensionado, totalizadores materializados, particionamento por
data, índice FULLTEXT na descrição, exportação assíncrona e réplica de leitura.
Cada item está em [docs/producao.md](docs/producao.md) com a medição que o
justifica — uma pendência só vale registrada se vier com número.
