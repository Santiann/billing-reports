# Frontend: fundação visual e telas

[← README](../README.md)

- [Fundação visual](#fundação-visual)
- [Página pública](#página-pública)
- [Estados de erro e carregamento](#estados-de-erro-e-carregamento)

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
