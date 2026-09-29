<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The file submitted for import.
 *
 * `mimes:csv,txt` and not just `csv`: the type a browser announces for a CSV varies with the
 * operating system and with what is installed — text/csv, text/plain and
 * application/vnd.ms-excel are all possible for the same file. Barring by the announced type
 * would refuse a good file; what guarantees the content is the reader, which fails with a
 * clear message if the header is not
 * lá.
 */
class ImportRequest extends FormRequest
{
    /** The upload cap, in kilobytes. */
    private const MAX_KB = 20_480;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.self::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecione um arquivo CSV.',
            'file.mimes' => 'O arquivo precisa ser um CSV.',
            'file.max' => 'O arquivo passa de '.(self::MAX_KB / 1024).' MB.',
        ];
    }

    /** The preview writes nothing: it analyses the file and returns what would happen. */
    public function isPreview(): bool
    {
        return $this->boolean('preview');
    }
}
