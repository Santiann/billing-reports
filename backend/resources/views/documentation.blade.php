{{--
    Documentação da API, montada no servidor.

    Zero JavaScript e zero CDN, e isso não é economia: é o argumento da página.
    Ela existe porque `curl localhost:8000` precisa responder a documentação, e
    uma página que depende de script para se montar não responde nada ao curl.
    Pela mesma razão não há fonte externa — abre sem internet, com as famílias
    que a máquina já tem.

    O tema é de especificação impressa: papel, tinta, fio de régua e numeração
    de seção. Existe folha de estilo de impressão no fim do arquivo, porque um
    documento que se chama "especificação" deveria sair bem no papel.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $spec['info']['title'] }}</title>
    <meta name="description" content="{{ $spec['info']['summary'] ?? '' }}">
    <style>
        :root {
            --papel: #f8f5ee;
            --papel-fundo: #efe9dc;
            --tinta: #1b1915;
            --tinta-fraca: #6d6459;
            --regua: #ddd4c2;
            --regua-forte: #c3b8a1;
            --leitura: #2b2620;
            --get: #2a4a78;
            --post: #7d4310;
            --put: #5a4a8a;
            --delete: #98241f;
            --destaque: #97291f;
            --codigo-fundo: #f2ede1;

            --serif: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, "Times New Roman", serif;
            --sans: "Optima", "Segoe UI", "Helvetica Neue", Helvetica, sans-serif;
            --mono: ui-monospace, "SF Mono", "Cascadia Mono", "JetBrains Mono", Menlo, Consolas, monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --papel: #16161a;
                --papel-fundo: #101014;
                --tinta: #ece7dd;
                --tinta-fraca: #9a9188;
                --regua: #2e2c30;
                --regua-forte: #45414a;
                --leitura: #d8d2c8;
                --get: #7fa8de;
                --post: #d99a5c;
                --put: #a99adb;
                --delete: #e08078;
                --destaque: #e08078;
                --codigo-fundo: #1d1d22;
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--papel-fundo);
            color: var(--leitura);
            font-family: var(--serif);
            font-size: 17px;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }

        /* Textura de papel: ruído leve, sem imagem externa. */
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: .035;
            background-image: radial-gradient(var(--tinta) .5px, transparent .5px);
            background-size: 3px 3px;
        }

        .folha {
            max-width: 1180px;
            margin: 0 auto;
            background: var(--papel);
            border-left: 1px solid var(--regua);
            border-right: 1px solid var(--regua);
            display: grid;
            grid-template-columns: 268px minmax(0, 1fr);
        }

        /* --- índice lateral ------------------------------------------- */

        .indice {
            border-right: 1px solid var(--regua);
            padding: 3rem 1.5rem 4rem 2rem;
            position: sticky;
            top: 0;
            align-self: start;
            max-height: 100vh;
            overflow-y: auto;
        }

        .indice h2 {
            font-family: var(--sans);
            font-size: .68rem;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: var(--tinta-fraca);
            font-weight: 600;
            margin: 2rem 0 .6rem;
        }

        .indice h2:first-child { margin-top: 0; }

        .indice ol { list-style: none; margin: 0; padding: 0; }

        .indice li { margin: 0 0 .25rem; }

        .indice a {
            display: grid;
            grid-template-columns: 3.1rem minmax(0, 1fr);
            gap: .4rem;
            align-items: baseline;
            text-decoration: none;
            color: var(--leitura);
            font-family: var(--mono);
            font-size: .74rem;
            padding: .2rem .35rem .2rem 0;
            border-left: 2px solid transparent;
            padding-left: .5rem;
            margin-left: -.5rem;
            transition: border-color .12s ease, color .12s ease;
        }

        .indice a:hover { border-left-color: var(--destaque); color: var(--tinta); }

        .indice a span:last-child { overflow-wrap: anywhere; }

        /* --- corpo ----------------------------------------------------- */

        main {
            padding: 3rem 3.5rem 6rem;
            counter-reset: secao;
            min-width: 0;
        }

        .capa { border-bottom: 3px double var(--regua-forte); padding-bottom: 2.5rem; }

        .etiqueta {
            font-family: var(--sans);
            font-size: .66rem;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: var(--tinta-fraca);
        }

        h1 {
            font-size: 2.6rem;
            line-height: 1.1;
            margin: .8rem 0 .5rem;
            color: var(--tinta);
            font-weight: 600;
            letter-spacing: -.015em;
        }

        .subtitulo { font-size: 1.12rem; color: var(--tinta-fraca); font-style: italic; margin: 0 0 1.5rem; }

        .ficha {
            display: flex;
            flex-wrap: wrap;
            gap: 0 2.5rem;
            font-family: var(--mono);
            font-size: .78rem;
            color: var(--tinta-fraca);
            border-top: 1px solid var(--regua);
            border-bottom: 1px solid var(--regua);
            padding: .7rem 0;
            margin-bottom: 2rem;
        }

        .ficha b { color: var(--tinta); font-weight: 600; }
        .ficha a { color: var(--destaque); }

        .prosa { max-width: 68ch; }
        .prosa h2 {
            font-size: 1.15rem;
            margin: 2.2rem 0 .6rem;
            color: var(--tinta);
            font-weight: 600;
        }
        .prosa p { margin: 0 0 1rem; }
        .prosa ul { margin: 0 0 1rem; padding-left: 1.2rem; }
        .prosa li { margin-bottom: .4rem; }
        .prosa code {
            font-family: var(--mono);
            font-size: .85em;
            background: var(--codigo-fundo);
            padding: .1em .35em;
            border-radius: 2px;
        }
        .prosa pre {
            font-family: var(--mono);
            font-size: .8rem;
            background: var(--codigo-fundo);
            border-left: 2px solid var(--regua-forte);
            padding: .9rem 1rem;
            overflow-x: auto;
            line-height: 1.5;
        }
        .prosa pre code { background: none; padding: 0; }
        .prosa table {
            border-collapse: collapse;
            font-family: var(--sans);
            font-size: .82rem;
            margin: 0 0 1rem;
        }
        .prosa th, .prosa td {
            border-bottom: 1px solid var(--regua);
            padding: .35rem .9rem .35rem 0;
            text-align: left;
        }
        .prosa th { color: var(--tinta-fraca); font-weight: 600; }

        /* --- seções por assunto ---------------------------------------- */

        .assunto { margin-top: 4rem; }

        .assunto > h2 {
            counter-increment: secao;
            counter-reset: endpoint;
            font-size: 1.75rem;
            font-weight: 600;
            color: var(--tinta);
            margin: 0 0 .3rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid var(--regua-forte);
            letter-spacing: -.01em;
        }

        .assunto > h2::before {
            content: counter(secao) ". ";
            color: var(--tinta-fraca);
            font-family: var(--mono);
            font-size: .75em;
        }

        .assunto > p { color: var(--tinta-fraca); max-width: 66ch; margin: .7rem 0 0; }

        /* --- endpoint --------------------------------------------------- */

        .endpoint {
            counter-increment: endpoint;
            padding: 2.5rem 0 1rem;
            border-bottom: 1px solid var(--regua);
            scroll-margin-top: 1rem;
        }

        .endpoint:last-child { border-bottom: none; }

        .chamada {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            gap: .7rem;
            font-family: var(--mono);
            font-size: .95rem;
            margin-bottom: .5rem;
        }

        .verbo {
            font-weight: 700;
            font-size: .7rem;
            letter-spacing: .1em;
            padding: .22rem .5rem;
            border: 1px solid currentColor;
            border-radius: 2px;
        }

        .verbo.GET { color: var(--get); }
        .verbo.POST { color: var(--post); }
        .verbo.PUT, .verbo.PATCH { color: var(--put); }
        .verbo.DELETE { color: var(--delete); }

        .rota { color: var(--tinta); font-weight: 600; overflow-wrap: anywhere; }

        .cadeado {
            font-family: var(--sans);
            font-size: .64rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--tinta-fraca);
            border: 1px dashed var(--regua-forte);
            padding: .14rem .45rem;
            border-radius: 2px;
        }

        .endpoint > h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--tinta);
            margin: .2rem 0 .8rem;
        }

        .endpoint > h3::before {
            content: counter(secao) "." counter(endpoint) "  ";
            font-family: var(--mono);
            font-size: .7em;
            color: var(--tinta-fraca);
            font-weight: 400;
        }

        .rotulo {
            font-family: var(--sans);
            font-size: .64rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--tinta-fraca);
            font-weight: 600;
            margin: 1.8rem 0 .6rem;
        }

        table.dados {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
            font-family: var(--sans);
        }

        table.dados th {
            text-align: left;
            font-weight: 600;
            color: var(--tinta-fraca);
            font-size: .68rem;
            letter-spacing: .1em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--regua-forte);
            padding: 0 1rem .4rem 0;
        }

        table.dados td {
            border-bottom: 1px solid var(--regua);
            padding: .55rem 1rem .55rem 0;
            vertical-align: top;
        }

        table.dados td:last-child, table.dados th:last-child { padding-right: 0; }

        .campo { font-family: var(--mono); color: var(--tinta); font-weight: 600; overflow-wrap: anywhere; }
        .tipo { font-family: var(--mono); font-size: .76rem; color: var(--tinta-fraca); overflow-wrap: anywhere; }
        .obrigatorio { color: var(--destaque); font-size: .7rem; letter-spacing: .08em; text-transform: uppercase; }
        .onde { font-family: var(--mono); font-size: .72rem; color: var(--tinta-fraca); }

        .exemplo {
            font-family: var(--mono);
            font-size: .76rem;
            line-height: 1.55;
            background: var(--codigo-fundo);
            border: 1px solid var(--regua);
            border-left: 3px solid var(--regua-forte);
            padding: .9rem 1.1rem;
            overflow-x: auto;
            margin: .5rem 0 0;
            white-space: pre;
            color: var(--leitura);
        }

        .resposta { display: flex; gap: 1rem; align-items: baseline; margin-top: 1.4rem; }

        .status {
            font-family: var(--mono);
            font-weight: 700;
            font-size: .9rem;
            min-width: 2.6rem;
        }

        .status.ok { color: var(--get); }
        .status.erro { color: var(--destaque); }

        .resposta-descricao { color: var(--leitura); font-size: .92rem; }
        .resposta-descricao p { margin: 0 0 .5rem; }
        .resposta-descricao p:last-child { margin-bottom: 0; }
        .resposta-descricao code {
            font-family: var(--mono);
            font-size: .85em;
            background: var(--codigo-fundo);
            padding: .1em .3em;
        }
        .resposta-descricao table { border-collapse: collapse; font-size: .8rem; margin: .5rem 0; }
        .resposta-descricao th, .resposta-descricao td {
            border-bottom: 1px solid var(--regua);
            padding: .25rem .9rem .25rem 0;
            text-align: left;
        }

        .tipo-conteudo {
            font-family: var(--mono);
            font-size: .68rem;
            color: var(--tinta-fraca);
            margin-top: .8rem;
        }

        .rodape {
            margin-top: 5rem;
            padding-top: 1.5rem;
            border-top: 3px double var(--regua-forte);
            font-family: var(--sans);
            font-size: .76rem;
            color: var(--tinta-fraca);
        }

        .rodape a { color: var(--destaque); }

        /* --- telas estreitas ------------------------------------------- */

        @media (max-width: 900px) {
            .folha { grid-template-columns: minmax(0, 1fr); }
            .indice {
                position: static;
                /* Limitado e rolável: sem isto o índice inteiro ocupa a
                   primeira tela e quem abre no celular não vê documentação
                   nenhuma sem rolar. */
                max-height: 45vh;
                overflow-y: auto;
                border-right: none;
                border-bottom: 1px solid var(--regua);
                padding: 1.5rem;
            }
            main { padding: 2rem 1.5rem 4rem; }
            h1 { font-size: 2rem; }
            body { font-size: 16px; }
        }

        /* --- impressão --------------------------------------------------
           Um documento que se chama especificação deveria sair bem no papel. */

        @media print {
            body { background: #fff; font-size: 10.5pt; }
            body::before { display: none; }
            .folha { display: block; max-width: none; border: none; }
            .indice { display: none; }
            main { padding: 0; }
            .endpoint { break-inside: avoid; }
            .exemplo { break-inside: avoid; border-left-width: 2px; }
            a { color: inherit; text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="folha">

    <aside class="indice">
        <div class="etiqueta">Índice</div>
        @foreach ($grupos as $tag => $endpoints)
            <h2>{{ $tag }}</h2>
            <ol>
                @foreach ($endpoints as $endpoint)
                    <li>
                        <a href="#{{ $endpoint['ancora'] }}">
                            <span class="verbo-mini">{{ $endpoint['metodo'] }}</span>
                            {{-- <wbr> antes da chave: sem ele, `/customers/{customer}`
                                 quebra no meio da palavra e vira "{custom er}". --}}
                            <span>{!! str_replace('{', '<wbr>{', e(Str::after($endpoint['caminho'], '/api') ?: '/')) !!}</span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endforeach
    </aside>

    <main>
        <header class="capa">
            <div class="etiqueta">Especificação da API · OpenAPI {{ $spec['openapi'] }}</div>
            <h1>{{ $spec['info']['title'] }}</h1>
            @isset($spec['info']['summary'])
                <p class="subtitulo">{{ $spec['info']['summary'] }}</p>
            @endisset

            <div class="ficha">
                <span>Versão <b>{{ $spec['info']['version'] }}</b></span>
                @foreach ($spec['servers'] ?? [] as $servidor)
                    <span>Servidor <b>{{ $servidor['url'] }}</b></span>
                @endforeach
                <span>Spec <a href="/openapi.yaml">openapi.yaml</a></span>
            </div>

            <div class="prosa">
                {!! Str::markdown($spec['info']['description'] ?? '', ['html_input' => 'escape']) !!}
            </div>
        </header>

        @foreach ($grupos as $tag => $endpoints)
            <section class="assunto">
                <h2 id="{{ Str::slug($tag) }}">{{ $tag }}</h2>

                @php $descricaoDaTag = collect($spec['tags'])->firstWhere('name', $tag)['description'] ?? null; @endphp
                @isset($descricaoDaTag)
                    <p>{{ $descricaoDaTag }}</p>
                @endisset

                @foreach ($endpoints as $endpoint)
                    <article class="endpoint" id="{{ $endpoint['ancora'] }}">
                        <div class="chamada">
                            <span class="verbo {{ $endpoint['metodo'] }}">{{ $endpoint['metodo'] }}</span>
                            <span class="rota">{{ $endpoint['caminho'] }}</span>
                            @unless ($endpoint['publica'])
                                <span class="cadeado">Bearer</span>
                            @endunless
                        </div>

                        <h3>{{ $endpoint['resumo'] }}</h3>

                        @isset($endpoint['descricao'])
                            <div class="prosa">{!! Str::markdown($endpoint['descricao'], ['html_input' => 'escape']) !!}</div>
                        @endisset

                        @if ($endpoint['parametros'] !== [])
                            <div class="rotulo">Parâmetros</div>
                            <table class="dados">
                                <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Onde</th>
                                    <th>Tipo</th>
                                    <th>Descrição</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($endpoint['parametros'] as $parametro)
                                    <tr>
                                        <td>
                                            <span class="campo">{{ $parametro['nome'] }}</span>
                                            @if ($parametro['obrigatorio'])
                                                <div class="obrigatorio">obrigatório</div>
                                            @endif
                                        </td>
                                        <td class="onde">{{ $parametro['local'] }}</td>
                                        <td class="tipo">
                                            {{ $parametro['tipo'] }}
                                            @isset($parametro['padrao'])
                                                <div>padrão: {{ $parametro['padrao'] }}</div>
                                            @endisset
                                        </td>
                                        <td>{{ $parametro['descricao'] }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif

                        @isset($endpoint['corpo'])
                            <div class="rotulo">
                                Corpo da requisição
                                @unless ($endpoint['corpo']['obrigatorio']) — opcional @endunless
                            </div>
                            @foreach ($endpoint['corpo']['exemplos'] as $exemplo)
                                <div class="tipo-conteudo">{{ $exemplo['tipo'] }}</div>
                                <pre class="exemplo">{{ $exemplo['exemplo'] }}</pre>
                            @endforeach
                        @endisset

                        <div class="rotulo">Respostas</div>
                        @foreach ($endpoint['respostas'] as $resposta)
                            <div class="resposta">
                                <span class="status {{ (int) $resposta['status'] < 400 ? 'ok' : 'erro' }}">
                                    {{ $resposta['status'] }}
                                </span>
                                <div class="resposta-descricao">
                                    {!! Str::markdown($resposta['descricao'], ['html_input' => 'escape']) !!}
                                </div>
                            </div>
                            @foreach ($resposta['exemplos'] as $exemplo)
                                <div class="tipo-conteudo">{{ $exemplo['tipo'] }}</div>
                                <pre class="exemplo">{{ $exemplo['exemplo'] }}</pre>
                            @endforeach
                        @endforeach
                    </article>
                @endforeach
            </section>
        @endforeach

        <footer class="rodape">
            <p>
                Documentação montada no servidor a partir de
                <a href="/openapi.yaml">resources/openapi.yaml</a>, sem JavaScript
                e sem CDN — <code>curl {{ $spec['servers'][0]['url'] ?? '' }}</code>
                devolve esta página inteira.
            </p>
            <p>
                A spec é verificada por teste contra as rotas registradas no
                Laravel, nas duas direções: rota sem entrada falha, entrada sem
                rota também.
            </p>
        </footer>
    </main>
</div>
</body>
</html>
