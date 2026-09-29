<?php

return [
    /*
     * The row cap for the PDF export.
     *
     * PDF is limited by nature: the document has to be paginated and assembled whole before it
     * can be delivered, so there is no streaming version like the CSV's. A report of hundreds
     * of thousands of rows would consume memory proportional to its size and produce a file
     * nobody reads.
     *
     * Above this cap the API answers 422 pointing at the CSV, which has no limit. It is a
     * design decision documented in the README, not a hidden failure.
     *
     * The value came from measurement, not estimation. dompdf's consumption on this report,
     * with nine columns:
     *
     *     500 rows ->   184 MB,   9.6s
     *   1,000 rows ->   420 MB,  17.9s
     *   2,000 rows -> 1,164 MB,  56.5s
     *   3,500 rows -> 2,965 MB, 210.0s
     *   5,000 rows -> blew past 3 GB
     *
     * The growth is superlinear: doubling the rows almost triples the memory. With a
     * memory_limit of 512M (see docker/php/app.ini), a thousand rows is the largest value that
     * fits comfortably.
     */
    'pdf_max_rows' => (int) env('REPORT_PDF_MAX_ROWS', 1000),
];
