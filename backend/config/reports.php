<?php

return [
    /*
     * Teto de linhas da exportação em PDF.
     *
     * PDF é limitado por natureza: o documento precisa ser paginado e montado
     * inteiro antes de ser entregue, então não existe versão em streaming como
     * a do CSV. Um relatório de centenas de milhares de linhas consumiria
     * memória proporcional ao tamanho e produziria um arquivo que ninguém lê.
     *
     * Acima deste teto a API responde 422 orientando o uso do CSV, que não tem
     * limite. É decisão de projeto documentada no README, não falha escondida.
     *
     * O valor saiu de medição, não de estimativa. Consumo do dompdf neste
     * relatório, com nove colunas:
     *
     *     500 linhas ->   184 MB,   9,6s
     *   1.000 linhas ->   420 MB,  17,9s
     *   2.000 linhas -> 1.164 MB,  56,5s
     *   3.500 linhas -> 2.965 MB, 210,0s
     *   5.000 linhas -> estourou 3 GB
     *
     * O crescimento é superlinear: dobrar as linhas quase triplica a memória.
     * Com memory_limit de 512M (ver docker/php/app.ini), mil linhas é o maior
     * valor que cabe com folga.
     */
    'pdf_max_rows' => (int) env('REPORT_PDF_MAX_ROWS', 1000),
];
