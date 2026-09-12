/** Uma linha que não entrou, com o motivo e o que ela trazia. */
export type ImportError = {
  /** Linha do ARQUIVO, contando o cabeçalho. */
  line: number;
  messages: string[];
  values: Record<string, string>;
};

export type ImportReport = {
  total_rows: number;
  valid_count: number;
  imported_count: number;
  error_count: number;
  /** A lista de erros tem teto; a contagem, não. */
  errors_truncated: boolean;
  errors: ImportError[];
  sample: Record<string, string>[];
};

export type ImportState = {
  /** "preview" mostra o que aconteceria; "import" já aconteceu. */
  mode?: "preview" | "import";
  report?: ImportReport;
  message?: string;
  errors?: Record<string, string[]>;
};
