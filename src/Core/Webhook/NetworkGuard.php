<?php

declare(strict_types=1);

namespace SWForms\Core\Webhook;

/**
 * SSRF protection for webhook targets: only http(s) URLs whose host resolves exclusively to public
 * addresses. Internal systems (an ERP in the local network) can be allowed explicitly by host name
 * (`erp.local`, `*.corp.example`), IP address or CIDR range (`10.0.5.0/24`).
 */
final class NetworkGuard
{
    public const OK = '';
    public const INVALID_URL = 'invalid_url';
    public const SCHEME = 'scheme';
    public const CREDENTIALS = 'credentials';
    public const UNRESOLVED = 'unresolved';
    public const PRIVATE_ADDRESS = 'private_address';

    /** Ranges that never count as public (RFC 6890 special purpose registries). */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** @var list<string> Lower case host names and wildcards. */
    private array $hosts = [];
    /** @var list<string> CIDR ranges (single addresses as /32 or /128). */
    private array $ranges = [];

    /**
     * @param list<string> $allowed Host names, `*.domain` wildcards, IP addresses or CIDR ranges.
     */
    public function __construct(array $allowed = [])
    {
        foreach ($allowed as $entry) {
            $entry = self::normalizeEntry($entry);
            if ($entry === null) {
                continue;
            }
            if (str_contains($entry, '/')) {
                $this->ranges[] = $entry;
            } else {
                $this->hosts[] = $entry;
            }
        }
    }

    /**
     * Canonical form of an allow list entry or null if it is invalid.
     */
    public static function normalizeEntry(string $entry): ?string
    {
        $entry = strtolower(trim($entry));
        if ($entry === '') {
            return null;
        }
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            return $entry . (str_contains($entry, ':') ? '/128' : '/32');
        }
        if (str_contains($entry, '/')) {
            [$ip, $bits] = explode('/', $entry, 2);
            $max = str_contains($ip, ':') ? 128 : 32;
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || !ctype_digit($bits) || (int) $bits > $max) {
                return null;
            }
            return $ip . '/' . (int) $bits;
        }
        $host = str_starts_with($entry, '*.') ? substr($entry, 2) : $entry;
        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) !== 1) {
            return null;
        }
        return $entry;
    }

    /**
     * Syntax check of a target URL (no network access).
     *
     * @return array{error: string, host: string, port: int}
     */
    public static function parseUrl(string $url): array
    {
        $fail = static fn (string $error): array => ['error' => $error, 'host' => '', 'port' => 0];
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return $fail(self::INVALID_URL);
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $fail(self::SCHEME);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $fail(self::CREDENTIALS);
        }
        $host = strtolower(trim($parts['host'], '[]'));
        return ['error' => self::OK, 'host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    /**
     * @param list<string> $ips All addresses the host resolved to.
     * @return string Error code or {@see self::OK}.
     */
    public function check(string $host, array $ips): string
    {
        if ($this->hostAllowed($host)) {
            return self::OK;
        }
        if ($ips === []) {
            return self::UNRESOLVED;
        }
        foreach ($ips as $ip) {
            if (!$this->ipAllowed($ip)) {
                return self::PRIVATE_ADDRESS;
            }
        }
        return self::OK;
    }

    public function hostAllowed(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        foreach ($this->hosts as $allowed) {
            if ($allowed === $host || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }
        return false;
    }

    public function ipAllowed(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($this->ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }
        return self::isPublic($ip);
    }

    public static function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        // IPv4 embedded in IPv6 (mapped ::ffff:0:0/96, NAT64 64:ff9b::/96) is judged by its IPv4 part.
        if (strlen($binary) === 16 && (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($binary, "\x00\x64\xff\x9b" . str_repeat("\0", 8)))) {
            $ipv4 = inet_ntop(substr($binary, 12));
            return $ipv4 !== false && self::isPublic($ipv4);
        }
        foreach (self::BLOCKED as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }
        return true;
    }

    public static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, '');
        $address = @inet_pton($ip);
        $network = @inet_pton($subnet);
        if ($address === false || $network === false || strlen($address) !== strlen($network)) {
            return false;
        }
        $bits = $bits === '' ? strlen($address) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($address, $network, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xff << (8 - $rest)) & 0xff;
        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
