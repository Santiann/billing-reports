# Uso de inteligência artificial

[← README](../README.md)

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

Outras skills guiaram commits específicos **sem estar no repositório**: elas
vivem no ambiente de quem desenvolve. Ficam declaradas aqui porque o enunciado
pede transparência sobre o uso de IA, e porque em cada caso é possível apontar
onde elas mudaram o resultado.

| Skill | Onde mudou o resultado |
|---|---|
| `dataviz` | Reprovou a primeira paleta do gráfico do dashboard por contraste insuficiente entre séries adjacentes — [ΔE 14,6 contra um piso de 15](performance.md#dashboard) — e corrigiu o uso de `tabular-nums`: figura proporcional para valor isolado, tabular só em coluna que alinha na vertical |
| `frontend-design` | A [fundação visual](frontend.md#fundação-visual): tipografia com personalidade, tokens semânticos e a decisão de não usar uma única classe `dark:` |
| `landing-page-design` e `copywriting` | A [página pública](frontend.md#página-pública): estrutura acima da dobra, e a recusa explícita de estatística fabricada — [nenhum número dela é inventado](frontend.md#nenhum-número-da-página-é-inventado) |
| `vercel-react-best-practices` | Padrões de Server Component, e o paralelismo de busca na ficha da cobrança, onde a trilha e a cobrança são buscadas juntas em vez de em sequência |

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
e três delas não sobreviveram ao contato com a medição.

- **O teto do PDF era 5.000.** Os testes passavam, porque testes usam poucas
  linhas. A exportação contra a base real estourou a memória com 3.577. A curva
  medida mostrou que 5.000 precisaria de mais de 3 GB. O teto virou 1.000, e a
  medição ficou registrada ao lado do valor.
- **O nome do serviço `backend`.** O `CLAUDE.md` fixa
  `API_URL_INTERNAL=http://backend`, e o plano de infra chamava de `backend` o
  php-fpm — que fala FastCGI, não HTTP. Todo fetch de Server Component
  falharia, e só dentro do Docker. O nginx passou a se chamar `backend` e o
  `CLAUDE.md` ganhou a nota de que o nome é load-bearing.
- **O `make lint` não provava o que dizia provar.** Ele passava na máquina de
  quem desenvolve porque o servidor de desenvolvimento havia gerado os tipos de
  rota do Next; num clone limpo, o typecheck falha. Quem mostrou foi o
  [CI](operacao.md#integração-contínua), no primeiro push. O passo que faltava entrou no
  alvo e no workflow, e a regra — verificação que depende de artefato gerado
  precisa gerá-lo — voltou para o `CLAUDE.md`.

Ambas as correções voltaram para o `CLAUDE.md`, que é o ponto: a configuração é
mantida junto do código e corrigida quando o código prova que ela está errada.
