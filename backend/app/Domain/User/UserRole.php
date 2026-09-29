<?php

namespace App\Domain\User;

/**
 * The system's two roles.
 *
 * Two, and not a permissions table: what the brief asks for is the distinction between whoever
 * operates and whoever only looks, and a per-resource permission matrix would be structure for
 * a problem this system does not have. If a third role shows up with rules of its own, then
 * yes.
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
