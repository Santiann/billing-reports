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

---

## Como executar

Pré-requisito único: **Docker com Compose v2**. Não é preciso ter PHP, Node ou
MySQL instalados.

```bash
git clone https://github.com/Santiann/billing-reports.git
cd billing-reports
git checkout joao-santian
docker compose up -d
```

É só isso — não há `.env` para copiar nem `composer install` para rodar à mão.
O entrypoint do backend resolve os dois (ver [Bootstrap automático](#bootstrap-automático-do-backend)).

Para acompanhar o boot:

```bash
docker compose logs -f
```

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

## Decisões técnicas desta etapa

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

**Credenciais em claro no `docker-compose.yml` e no `.env.example`.** É um
ambiente de avaliação local, e o critério de aceite exige que subir não dependa
de preencher segredo nenhum. Os valores do serviço `mysql` espelham os de
`backend/.env.example`; mudar um exige mudar o outro.

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
