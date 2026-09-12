<?php

namespace App\Domain\User;

/**
 * Os dois perfis do sistema.
 *
 * Dois, e não uma tabela de permissões: o que o teste pede é a distinção entre
 * quem opera e quem consulta, e uma matriz de permissão por recurso seria
 * estrutura para um problema que este sistema não tem. Se um terceiro perfil
 * aparecer com regra própria, aí sim.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Viewer => 'Consulta',
        };
    }

    /** Quem pode criar, editar, importar e registrar pagamento. */
    public function canWrite(): bool
    {
        return $this === self::Admin;
    }
}
