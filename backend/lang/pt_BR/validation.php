<?php

/**
 * Mensagens de validação em português.
 *
 * A interface é em português e as mensagens de erro chegam nela cruas, campo a
 * campo. Sem este arquivo o usuário veria "The name field is required."
 * misturado com as mensagens customizadas em português.
 *
 * Só as regras efetivamente usadas no projeto: um arquivo completo seria
 * centenas de linhas mortas.
 */
return [
    'after_or_equal' => 'O campo :attribute deve ser uma data igual ou posterior a :date.',
    'before_or_equal' => 'O campo :attribute deve ser uma data igual ou anterior a :date.',
    'boolean' => 'O campo :attribute deve ser verdadeiro ou falso.',
    'date' => 'O campo :attribute não é uma data válida.',
    'decimal' => 'O campo :attribute deve ter :decimal casas decimais.',
    'email' => 'O campo :attribute deve ser um e-mail válido.',
    'enum' => 'O valor selecionado em :attribute é inválido.',
    'exists' => 'O valor selecionado em :attribute é inválido.',
    'in' => 'O valor selecionado em :attribute é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'numeric' => 'O campo :attribute deve ser um número.',
    'regex' => 'O formato do campo :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute deve ser um texto.',
    'unique' => 'Este :attribute já está em uso.',

    'max' => [
        'array' => 'O campo :attribute não pode ter mais que :max itens.',
        'file' => 'O campo :attribute não pode ser maior que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute não pode ter mais que :max caracteres.',
    ],

    'min' => [
        'array' => 'O campo :attribute deve ter pelo menos :min itens.',
        'file' => 'O campo :attribute deve ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],

    /**
     * Nomes de campo em português, para a mensagem não citar a coluna do banco.
     */
    'attributes' => [
        'name' => 'nome',
        'document' => 'documento',
        'email' => 'e-mail',
        'status' => 'status',
        'password' => 'senha',
        'search' => 'busca',
        'sort' => 'ordenação',
        'direction' => 'direção',
        'per_page' => 'itens por página',
        'page' => 'página',
        'customer_id' => 'cliente',
        'description' => 'descrição',
        'original_amount' => 'valor original',
        'monthly_interest_rate' => 'taxa de juros mensal',
        'issue_date' => 'data de emissão',
        'due_date' => 'data de vencimento',
        'payment_date' => 'data de pagamento',
    ],
];
