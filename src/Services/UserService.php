<?php

namespace Metricool\Services;

use PDO;

class UserService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{id: int, email: string, role: string, brand_id: ?int, display_name: string, active: bool}|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '' || $password === '') {
            return null;
        }

        $this->bootstrapAdminFromEnvIfMissing();

        $user = $this->findRecordByEmail($email);
        if ($user === null || !$this->isUserActive($user)) {
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        $this->touchLastLogin((int) $user['id']);

        return $this->mapUser($user);
    }

    /**
     * @param array{id?: mixed, email?: mixed, display_name?: mixed, brand_id?: mixed, password?: mixed, active?: mixed} $data
     * @return array{id: int, email: string, role: string, brand_id: int, display_name: string, active: bool}
     */
    public function saveCompanyUser(array $data): array
    {
        $id = isset($data['id']) && $data['id'] !== '' ? (int) $data['id'] : null;
        $email = $this->normalizeEmail((string) ($data['email'] ?? ''));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $brandId = (int) ($data['brand_id'] ?? 0);
        $password = (string) ($data['password'] ?? '');
        $active = $this->normalizeBool($data['active'] ?? true);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Correo electrónico no válido');
        }

        if ($displayName === '') {
            throw new \InvalidArgumentException('Nombre requerido');
        }

        if ($brandId <= 0 || !$this->brandExists($brandId)) {
            throw new \InvalidArgumentException('Marca no válida');
        }

        $existingByEmail = $this->findRecordByEmail($email);
        if ($id === null) {
            if ($password === '') {
                throw new \InvalidArgumentException('La contraseña es requerida para nuevas cuentas');
            }

            $this->validatePassword($password);

            if ($existingByEmail !== null) {
                throw new \InvalidArgumentException('Ya existe un usuario con ese correo');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO dashboard_users (brand_id, email, password_hash, role, display_name, is_active, created_at, updated_at)
                 VALUES (:brand_id, :email, :password_hash, :role, :display_name, :is_active, :created_at, :updated_at)'
            );
            $stmt->execute([
                'brand_id' => $brandId,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'company',
                'display_name' => $displayName,
                'is_active' => $active ? 1 : 0,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);

            return $this->requireCompanyUser((int) $this->pdo->lastInsertId());
        }

        $existing = $this->findRecordById($id);
        if ($existing === null || (string) $existing['role'] !== 'company') {
            throw new \InvalidArgumentException('Cuenta de empresa no encontrada');
        }

        if ($existingByEmail !== null && (int) $existingByEmail['id'] !== $id) {
            throw new \InvalidArgumentException('Ya existe un usuario con ese correo');
        }

        $fields = [
            'brand_id = :brand_id',
            'email = :email',
            'display_name = :display_name',
            'is_active = :is_active',
            'updated_at = :updated_at',
        ];
        $params = [
            'id' => $id,
            'brand_id' => $brandId,
            'email' => $email,
            'display_name' => $displayName,
            'is_active' => $active ? 1 : 0,
            'updated_at' => $this->now(),
        ];

        if ($password !== '') {
            $this->validatePassword($password);
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE dashboard_users SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        return $this->requireCompanyUser($id);
    }

    /**
     * @return list<array{id: int, email: string, role: string, brand_id: int, display_name: string, active: bool, brand_name: string}>
     */
    public function getCompanyUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id, u.email, u.role, u.brand_id, u.display_name, u.is_active, b.name AS brand_name
             FROM dashboard_users u
             INNER JOIN brands b ON b.id = u.brand_id
             WHERE u.role = \'company\'
             ORDER BY b.name ASC, u.email ASC'
        );

        $rows = $stmt->fetchAll();

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'role' => 'company',
                'brand_id' => (int) $row['brand_id'],
                'display_name' => (string) $row['display_name'],
                'active' => $this->isUserActive($row),
                'brand_name' => (string) $row['brand_name'],
            ];
        }, $rows);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getBrands(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM brands ORDER BY name ASC');

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $stmt->fetchAll());
    }

    /**
     * @return array{id: int, email: string, role: string, brand_id: int, display_name: string, active: bool}|null
     */
    public function getCompanyUserById(int $id): ?array
    {
        $row = $this->findRecordById($id);
        if ($row === null || (string) $row['role'] !== 'company') {
            return null;
        }

        return $this->mapUser($row);
    }

    /**
     * @return array{id: int, email: string, role: string, brand_id: ?int, display_name: string, active: bool}|null
     */
    public function getActiveUserById(int $id): ?array
    {
        $row = $this->findRecordById($id);
        if ($row === null || !$this->isUserActive($row)) {
            return null;
        }

        return $this->mapUser($row);
    }

    private function bootstrapAdminFromEnvIfMissing(): void
    {
        if ($this->hasAnyAdmin()) {
            return;
        }

        $email = $this->normalizeEmail($this->env('DASHBOARD_ADMIN_EMAIL'));
        $password = $this->env('DASHBOARD_ADMIN_PASSWORD');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            return;
        }

        if ($this->findRecordByEmail($email) !== null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO dashboard_users (brand_id, email, password_hash, role, display_name, is_active, created_at, updated_at)
             VALUES (:brand_id, :email, :password_hash, :role, :display_name, :is_active, :created_at, :updated_at)'
        );
        $stmt->execute([
            'brand_id' => null,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
            'display_name' => 'Administrador',
            'is_active' => 1,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function hasAnyAdmin(): bool
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM dashboard_users WHERE role = 'admin'");

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecordByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dashboard_users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRecordById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dashboard_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function brandExists(int $brandId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM brands WHERE id = :id');
        $stmt->execute(['id' => $brandId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function touchLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dashboard_users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'last_login_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * @return array{id: int, email: string, role: string, brand_id: ?int, display_name: string, active: bool}
     */
    private function mapUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'role' => (string) $row['role'],
            'brand_id' => $row['brand_id'] !== null ? (int) $row['brand_id'] : null,
            'display_name' => (string) $row['display_name'],
            'active' => $this->isUserActive($row),
        ];
    }

    /**
     * @return array{id: int, email: string, role: string, brand_id: int, display_name: string, active: bool}
     */
    private function requireCompanyUser(int $id): array
    {
        $user = $this->getCompanyUserById($id);
        if ($user === null || $user['brand_id'] === null) {
            throw new \RuntimeException('No se pudo cargar la cuenta de empresa');
        }

        /** @var array{id: int, email: string, role: string, brand_id: int, display_name: string, active: bool} $user */
        return $user;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('La contraseña debe tener al menos 8 caracteres');
        }
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    private function isUserActive(array $row): bool
    {
        return (int) ($row['is_active'] ?? 0) === 1;
    }

    private function env(string $key): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return is_string($value) ? trim($value) : '';
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
