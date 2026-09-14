<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        /*
         * Limite geral da API, além do limite específico do login.
         *
         * O motivo não é força bruta — é custo. O relatório agrega o conjunto
         * filtrado inteiro, e uma consulta sem cache no recorte de um ano leva
         * doze segundos de banco. Sem teto, um cliente em laço derruba o
         * serviço para todos usando credencial legítima.
         *
         * A contagem é por USUÁRIO quando há um, e só cai no IP para as rotas
         * públicas. Contar sempre por IP seria errado aqui: o frontend chama a
         * API pelo servidor do Next, então todas as requisições da aplicação
         * chegam do mesmo endereço e um usuário ativo limitaria os outros.
         */
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
