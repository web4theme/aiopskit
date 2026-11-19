<?php
declare(strict_types=1);

namespace AIOpsKit;

final class Util
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(mixed $value, int $flags = 0): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $flags);
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory: ' . $dir);
        }
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        self::ensureDir(dirname($path));
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write: ' . $path);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot replace: ' . $path);
        }
    }

    public static function appendLine(string $path, array $row): void
    {
        self::ensureDir(dirname($path));
        file_put_contents($path, self::json($row) . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function readJsonFile(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : $default;
    }

    public static function truncate(string $value, int $limit = 200): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, max(1, $limit - 1)) . '…';
    }

    public static function csvRow(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $value = (string) $cell;
            if (strpbrk($value, ",\"\r\n") !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            $out[] = $value;
        }
        return implode(',', $out);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function mask(?string $secret): string
    {
        $secret = (string) $secret;
        if ($secret === '') {
            return '';
        }
        if (strlen($secret) <= 8) {
            return str_repeat('*', strlen($secret));
        }
        return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
    }
}

final class Config
{
    private static ?array $data = null;

    public static function load(?string $path = null): array
    {
        if (self::$data !== null && $path === null) {
            return self::$data;
        }
        $path ??= dirname(__DIR__) . '/config/config.php';
        $data = is_file($path) ? (require $path) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $defaults = [
            'storage' => dirname(__DIR__) . '/storage',
            'timezone' => 'UTC',
            'user_agent' => 'AIOpsKit/1.0',
            'http' => ['timeout' => 15, 'max_bytes' => 2097152],
            'odoo' => ['url' => '', 'db' => '', 'user' => '', 'password' => '', 'models' => [], 'allow_write' => false],
        ];
        $data = array_replace_recursive($defaults, $data);
        $envStorage = getenv('AIOPSKIT_STORAGE');
        if (is_string($envStorage) && $envStorage !== '') {
            $data['storage'] = $envStorage;
        }
        self::$data = $data;
        return $data;
    }

    /** Test helper: override configuration for the current process. */
    public static function set(array $overrides): void
    {
        self::$data = array_replace_recursive(self::load(), $overrides);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::load();
        $cursor = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function storage(string $sub = ''): string
    {
        $base = (string) self::get('storage');
        $path = $sub === '' ? $base : rtrim($base, '/') . '/' . ltrim($sub, '/');
        Util::ensureDir($sub === '' || str_contains($sub, '.') ? dirname($path) : $path);
        return $path;
    }
}

final class Db
{
    /** @var array<string,\PDO> */
    private static array $pool = [];

    public static function open(string $path): \PDO
    {
        if (isset(self::$pool[$path])) {
            return self::$pool[$path];
        }
        Util::ensureDir(dirname($path));
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pool[$path] = $pdo;
        return $pdo;
    }

    public static function migrate(\PDO $pdo, array $statements): void
    {
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }
}

final class Http
{
    /** @var callable|null */
    private static $transport = null;

    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string,url:string,error:?string,time:float}
     */
    public static function request(string $url, array $options = []): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return self::error('Only http/https URLs are supported.');
        }
        if (self::$transport !== null) {
            return (self::$transport)($url, $options);
        }
        if (self::isForbiddenHost($url) && empty($options['allow_private'])) {
            return self::error('Refusing to call a private or loopback address.');
        }
        $timeout = (int) ($options['timeout'] ?? 15);
        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $headers = (array) ($options['headers'] ?? []);
        $responseHeaders = [];
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => (string) ($options['user_agent'] ?? Config::get('user_agent')),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return strlen($line);
                }
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];
                    return strlen($line);
                }
                $pos = strpos($trimmed, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($trimmed, 0, $pos)))] = trim(substr($trimmed, $pos + 1));
                }
                return strlen($line);
            },
        ]);
        if ($method !== 'GET' && $method !== 'HEAD') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (isset($options['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $options['body']);
            }
        }
        $body = curl_exec($ch);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($body === false) {
            $body = '';
            $error ??= 'Request failed.';
        }
        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => (string) $body,
            'url' => $finalUrl !== '' ? $finalUrl : $url,
            'error' => $error,
            'time' => round(microtime(true) - $started, 3),
        ];
    }

    public static function postJson(string $url, array $payload, array $options = []): array
    {
        return self::request($url, $options + [
            'method' => 'POST',
            'headers' => ['Content-Type: application/json; charset=utf-8'],
            'body' => Util::json($payload),
        ]);
    }

    public static function decode(array $response): mixed
    {
        return json_decode($response['body'], true);
    }

    public static function isForbiddenHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }
        $host = trim(strtolower($host), '[]');
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        $ips = [];
        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? '';
        }
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }
        if ($ips === []) {
            return true;
        }
        foreach ($ips as $ip) {
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string,url:string,error:?string,time:float}
     */
    public static function error(string $message): array
    {
        return ['status' => 0, 'headers' => [], 'body' => '', 'url' => '', 'error' => $message, 'time' => 0.0];
    }
}

final class Cli
{
    public static function options(array $args): array
    {
        $out = ['_' => []];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/i', $arg, $m)) {
                $key = str_replace('-', '_', strtolower($m[1]));
                $out[$key] = $m[2] ?? true;
            } else {
                $out['_'][] = $arg;
            }
        }
        return $out;
    }

    public static function out(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    public static function err(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }

    public static function table(array $rows, array $headers = []): string
    {
        if ($rows === []) {
            return '(empty)';
        }
        if ($headers === []) {
            $headers = array_keys((array) $rows[0]);
        }
        $widths = [];
        foreach ($headers as $h) {
            $widths[$h] = mb_strlen((string) $h);
        }
        foreach ($rows as $row) {
            foreach ($headers as $h) {
                $widths[$h] = max($widths[$h], mb_strlen((string) ($row[$h] ?? '')));
            }
        }
        $line = static function (array $cells) use ($widths, $headers): string {
            $parts = [];
            foreach ($headers as $h) {
                $parts[] = str_pad((string) ($cells[$h] ?? ''), $widths[$h]);
            }
            return implode('  ', $parts);
        };
        $out = [$line(array_combine($headers, $headers))];
        $out[] = implode('  ', array_map(static fn ($w) => str_repeat('-', $w), $widths));
        foreach ($rows as $row) {
            $out[] = $line((array) $row);
        }
        return implode(PHP_EOL, $out);
    }
}

final class View
{
    public static function render(string $template, array $data = []): string
    {
        $file = dirname(__DIR__) . '/views/' . $template . '.php';
        if (!is_file($file)) {
            return 'View not found: ' . Util::e($template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo Util::json($payload);
    }
}
