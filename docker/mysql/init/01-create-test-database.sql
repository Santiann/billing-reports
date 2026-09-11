-- Banco separado para a suíte de testes.
--
-- Os testes PRECISAM rodar em MySQL, não em SQLite: a regra central deste
-- projeto é que o valor atualizado de uma cobrança seja calculável em SQL, e o
-- teste de consistência compara a face SQL do InterestCalculator com a face
-- PHP. Em SQLite esse teste validaria um motor que não é o de produção —
-- POW(), DATEDIFF() e a precisão de DECIMAL se comportam de outro jeito.
--
-- Banco à parte do de desenvolvimento porque RefreshDatabase derruba e recria
-- o schema a cada execução.
CREATE DATABASE IF NOT EXISTS faturamento_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON faturamento_test.* TO 'faturamento'@'%';
FLUSH PRIVILEGES;
