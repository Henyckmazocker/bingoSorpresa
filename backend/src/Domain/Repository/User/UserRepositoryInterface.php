<?php
declare(strict_types=1);

namespace App\Domain\Repository\User;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;

/**
 * Repositorio de usuarios (solo CRUD de `users`).
 */
interface UserRepositoryInterface
{
    /**
     * Busca un usuario por su Google ID
     */
    public function findByGoogleId(GoogleId $googleId): ?User;

    /**
     * Busca un usuario por su ID numérico
     */
    public function findById(int $id): ?User;

    /**
     * Busca un usuario por su email
     */
    public function findByEmail(Email $email): ?User;

    /**
     * Guarda un nuevo usuario
     */
    public function save(User $user): User;

    /**
     * Actualiza un usuario existente
     */
    public function update(User $user): User;

    /**
     * Elimina un usuario (soft delete)
     */
    public function delete(int $id): void;
}
