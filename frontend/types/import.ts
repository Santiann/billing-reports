/** A row that did not go in, with the reason and what it carried. */
export type ImportError = {
  /** The line in the FILE, counting the header. */
  line: number;
  messages: string[];
  values: Record<string, string>;
};

export type ImportReport = {
  total_rows: number;
  valid_count: number;
  imported_count: number;
  error_count: number;
  /** The error list is capped; the count is not. */
  errors_truncated: boolean;
  errors: ImportError[];
  sample: Record<string, string>[];
};

export type ImportState = {
  /** "preview" shows what would happen; "import" already happened. */
  mode?: "preview" | "import";
  report?: ImportReport;
  message?: string;
  errors?: Record<string, string[]>;
};
