# Melhorias que ficariam para produção

[← README](../README.md)

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
2](performance.md#cache-dos-totalizadores) e leva o recorte de um ano de 12,9 s para cerca de
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
