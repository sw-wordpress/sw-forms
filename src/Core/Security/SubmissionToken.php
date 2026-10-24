<?php

declare(strict_types=1);

namespace SWForms\Core\Security;

/**
 * Signed, form bound submission token: `base64url(json{f,t,j}) . "." . base64url(hmac)`.
 *
 * Replaces WordPress nonces for anonymous submissions (nonces break on cached pages). It carries
 * the issue time for the minimum fill-in time check and a unique id that is consumed on success
 * (double submit protection).
 */
final class SubmissionToken
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const TOO_FAST = 'too_fast';

    public function __construct(private readonly string $secret)
    {
    }

    public function issue(int $formId, int $issuedAt, string $id): string
    {
        $payload = self::encode((string) json_encode(['f' => $formId, 't' => $issuedAt, 'j' => $id]));
        return $payload . '.' . self::encode($this->sign($payload));
    }

    /**
     * @return array{status: string, id: string, issued: int}
     */
    public function verify(string $token, int $formId, int $now, int $minAge, int $maxAge): array
    {
        $invalid = ['status' => self::INVALID, 'id' => '', 'issued' => 0];
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return $invalid;
        }
        [$payload, $signature] = $parts;
        if (!hash_equals(self::encode($this->sign($payload)), $signature)) {
            return $invalid;
        }
        $data = json_decode(self::decode($payload), true);
        if (!is_array($data) || ($data['f'] ?? null) !== $formId || !is_int($data['t'] ?? null) || !is_string($data['j'] ?? null)) {
            return $invalid;
        }
        $age = $now - $data['t'];
        $status = self::OK;
        if ($age > $maxAge || $age < -60) {
            $status = self::EXPIRED;
        } elseif ($age < $minAge) {
            $status = self::TOO_FAST;
        }
        return ['status' => $status, 'id' => $data['j'], 'issued' => $data['t']];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
