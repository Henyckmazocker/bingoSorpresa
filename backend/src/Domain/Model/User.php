<?php
declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\Timestamp;
use InvalidArgumentException;

class User
{
    private ?int $id;
    private GoogleId $googleId;
    private Email $email;
    private string $name;
    private ?string $picture;
    private Timestamp $createdAt;
    private Timestamp $updatedAt;
    private ?Timestamp $lastLogin;
    private bool $isActive;
    private int $storageBytes;

    public function __construct(
        ?int $id,
        GoogleId $googleId,
        Email $email,
        string $name,
        ?string $picture = null,
        ?Timestamp $createdAt = null,
        ?Timestamp $updatedAt = null,
        ?Timestamp $lastLogin = null,
        bool $isActive = true,
        int $storageBytes = 0
    ) {
        $this->id = $id;
        $this->googleId = $googleId;
        $this->email = $email;
        $this->setName($name);
        $this->picture = $picture;
        $this->createdAt = $createdAt ?? Timestamp::now();
        $this->updatedAt = $updatedAt ?? Timestamp::now();
        $this->lastLogin = $lastLogin;
        $this->isActive = $isActive;
        $this->storageBytes = $storageBytes;
    }

    // Getters
    public function getId(): ?int { return $this->id; }
    public function getGoogleId(): GoogleId { return $this->googleId; }
    public function getEmail(): Email { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getPicture(): ?string { return $this->picture; }
    public function getCreatedAt(): Timestamp { return $this->createdAt; }
    public function getUpdatedAt(): Timestamp { return $this->updatedAt; }
    public function getLastLogin(): ?Timestamp { return $this->lastLogin; }
    public function isActive(): bool { return $this->isActive; }
    /** Suma de uploads.bytes; la mantiene QuotaService, no se escribe desde aquí. */
    public function getStorageBytes(): int { return $this->storageBytes; }

    // Setters with validation
    public function setGoogleId(GoogleId $googleId): void
    {
        $this->googleId = $googleId;
    }

    public function setEmail(Email $email): void
    {
        $this->email = $email;
    }

    public function setName(string $name): void
    {
        if (empty(trim($name))) {
            throw new InvalidArgumentException('Name cannot be empty');
        }
        $this->name = trim($name);
    }

    public function setPicture(?string $picture): void
    {
        $this->picture = $picture;
    }

    public function updateLastLogin(): void
    {
        $this->lastLogin = Timestamp::now();
        $this->updatedAt = Timestamp::now();
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->updatedAt = Timestamp::now();
    }

    // Factory method
    public static function create(array $data): self
    {
        return new self(
            null, // ID will be set by repository
            GoogleId::fromString($data['google_id']),
            Email::fromString($data['email']),
            $data['name'],
            $data['picture'] ?? null,
            null, // createdAt will be set by constructor
            null, // updatedAt will be set by constructor
            null, // lastLogin
            $data['is_active'] ?? true
        );
    }

    // Factory method for Google OAuth registration
    public static function registerWithGoogle(
        GoogleId $googleId,
        Email $email,
        string $name,
        ?string $picture = null
    ): self {
        return new self(
            null, // ID will be set by repository
            $googleId,
            $email,
            $name,
            $picture,
            null, // createdAt will be set by constructor
            null, // updatedAt will be set by constructor
            null, // lastLogin
            true  // is_active
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'google_id' => $this->googleId->toString(),
            'email' => $this->email->toString(),
            'name' => $this->name,
            'picture' => $this->picture,
            'created_at' => $this->createdAt->toUnixTimestamp(),
            'updated_at' => $this->updatedAt->toUnixTimestamp(),
            'last_login' => $this->lastLogin?->toUnixTimestamp(),
            'is_active' => $this->isActive,
            'storage_bytes' => $this->storageBytes,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            GoogleId::fromString($data['google_id']),
            Email::fromString($data['email']),
            $data['name'],
            $data['picture'] ?? null,
            isset($data['created_at']) ? Timestamp::fromUnixTimestamp($data['created_at']) : null,
            isset($data['updated_at']) ? Timestamp::fromUnixTimestamp($data['updated_at']) : null,
            isset($data['last_login']) ? Timestamp::fromUnixTimestamp($data['last_login']) : null,
            $data['is_active'] ?? true,
            (int) ($data['storage_bytes'] ?? 0)
        );
    }
}
