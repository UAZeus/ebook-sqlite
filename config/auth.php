<?php

declare(strict_types=1);

namespace App\Config;

class Auth
{
    private static ?Auth $instance = null;
    private ?array $user = null;
    private Database $db;

    private const REMEMBER_ME_DAYS = 30;
    private const PASSWORD_REGEX = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,72}$/';

    private static function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private static function rememberCookieParams(): array
    {
        // secure=true only over HTTPS; SameSite=Lax matches session cookies.
        // setcookie() signature: (name, value, expires, path, domain, secure, httponly)
        return ['/', '', self::isHttps(), true];
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function verifyCsrf(string $token): bool
    {
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public function setFlash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public function getFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    private function __construct(Database $db)
    {
        $this->db = $db;
        $this->startSession();
        $this->loadUser();
    }

    public static function init(Database $db): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Auth not initialized. Call Auth::init($db) first.');
        }
        return self::$instance;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    private function loadUser(): void
    {
        $userId = $_SESSION['user_id'] ?? null;

        if ($userId === null) {
            $this->tryRememberMe();
            return;
        }

        $user = $this->db->fetchOne(
            'SELECT user_id, name, email, role, created_at FROM users WHERE user_id = ?',
            [$userId]
        );
        if ($user) {
            $this->user = $user;
        } else {
            unset($_SESSION['user_id']);
        }
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function role(): string
    {
        return $this->user['role'] ?? 'viewer';
    }

    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    public function isLibrarian(): bool
    {
        return $this->role() === 'librarian';
    }

    public function isViewer(): bool
    {
        return $this->role() === 'viewer';
    }

    public function require(): array
    {
        if (!$this->isLoggedIn()) {
            $redirect = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: /login?redirect=' . urlencode($redirect));
            exit;
        }
        return $this->user;
    }

    public function requireRole(string ...$roles): array
    {
        $user = $this->require();
        if (!in_array($this->role(), $roles, true)) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
            exit;
        }
        return $user;
    }

    public static function isGmail(string $email): ?string
    {
        $domain = strtolower(explode('@', $email)[1] ?? '');
        if ($domain !== 'gmail.com') {
            return 'Only official Gmail accounts are allowed.';
        }
        return null;
    }

    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < 8) return 'Password must be at least 8 characters.';
        if (strlen($password) > 72) return 'Password must be at most 72 characters.';
        if (!preg_match(self::PASSWORD_REGEX, $password)) {
            return 'Password must include uppercase, lowercase, digit, and special character.';
        }
        return null;
    }

    private function tryRememberMe(): void
    {
        $token = $_COOKIE['remember_me'] ?? null;
        if (!$token) return;

        $hash = hash('sha256', $token);
        $row = $this->db->fetchOne(
            "SELECT r.user_id, r.expires_at FROM remember_tokens r WHERE r.token = ? AND r.expires_at > datetime('now')",
            [$hash]
        );
        if (!$row) {
            [$cPath, $cDomain, $cSecure, $cHttpOnly] = self::rememberCookieParams();
            setcookie('remember_me', '', time() - 3600, $cPath, $cDomain, $cSecure, $cHttpOnly);
            return;
        }

        $this->db->execute('DELETE FROM remember_tokens WHERE token = ?', [$hash]);
        $newToken = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newToken);
        $expires = date('Y-m-d H:i:s', time() + self::REMEMBER_ME_DAYS * 86400);
        $this->db->execute(
            'INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
            [$row['user_id'], $newHash, $expires]
        );
        [$cPath, $cDomain, $cSecure, $cHttpOnly] = self::rememberCookieParams();
        setcookie('remember_me', $newToken, strtotime($expires), $cPath, $cDomain, $cSecure, $cHttpOnly);

        $_SESSION['user_id'] = $row['user_id'];
        session_regenerate_id(true);
        $this->loadUser();
    }

    public function login(string $email, string $password, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $user = $this->db->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Invalid email or password.'];
        }

        $_SESSION['user_id'] = $user['user_id'];
        session_regenerate_id(true);
        $this->user = $user;

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + self::REMEMBER_ME_DAYS * 86400);
            $this->db->execute(
                'INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)',
                [$user['user_id'], $hash, $expires]
            );
            [$cPath, $cDomain, $cSecure, $cHttpOnly] = self::rememberCookieParams();
            setcookie('remember_me', $token, strtotime($expires), $cPath, $cDomain, $cSecure, $cHttpOnly);
        }

        // Never expose the password hash: return only the public profile.
        $safe = $this->db->fetchOne(
            'SELECT user_id, name, email, role, created_at FROM users WHERE user_id = ?',
            [$user['user_id']]
        );
        $this->user = $safe ?: $this->user;
        return ['success' => true, 'user' => $this->user];
    }

    public function register(string $name, string $email, string $password, string $role = 'viewer'): array
    {
        // Role is server-controlled: public registration can only create viewers.
        // Librarian/admin promotion happens via PUT /api/admin/users/:id/role.
        $role = 'viewer';
        $email = strtolower(trim($email));

        $pwErr = self::validatePassword($password);
        if ($pwErr) return ['success' => false, 'error' => $pwErr];

        $existing = $this->db->fetchOne('SELECT user_id FROM users WHERE email = ?', [$email]);
        if ($existing) return ['success' => false, 'error' => 'Email already registered.'];

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $userId = $this->generateId();
        $this->db->execute(
            'INSERT INTO users (user_id, name, email, password, role) VALUES (?, ?, ?, ?, ?)',
            [$userId, trim($name), $email, $hash, $role]
        );
        $_SESSION['user_id'] = $userId;
        session_regenerate_id(true);
        $this->user = $this->db->fetchOne('SELECT user_id, name, email, role, created_at FROM users WHERE user_id = ?', [$userId]);
        return ['success' => true, 'user_id' => $userId];
    }

    public function logout(): void
    {
        if ($this->user) {
            $this->db->execute('DELETE FROM remember_tokens WHERE user_id = ?', [$this->user['user_id']]);
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        [$cPath, $cDomain, $cSecure, $cHttpOnly] = self::rememberCookieParams();
        setcookie('remember_me', '', time() - 3600, $cPath, $cDomain, $cSecure, $cHttpOnly);
        session_destroy();
        $this->user = null;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
