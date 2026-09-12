<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

/**
 * O arquivo enviado para importação.
 *
 * `mimes:csv,txt` e não só `csv`: o tipo que o browser anuncia para um CSV
 * varia com o sistema operacional e com o que está instalado — text/csv,
 * text/plain e application/vnd.ms-excel são todos possíveis para o mesmo
 * arquivo. Barrar pelo tipo anunciado recusaria arquivo bom; o que garante o
 * conteúdo é o leitor, que falha com mensagem clara se o cabeçalho não estiver
 * lá.
 */
class ImportRequest extends FormRequest
{
    /** Teto do upload, em kilobytes. */
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

    /** A prévia não grava: analisa o arquivo e devolve o que aconteceria. */
    public function isPreview(): bool
    {
        return $this->boolean('preview');
    }
}
