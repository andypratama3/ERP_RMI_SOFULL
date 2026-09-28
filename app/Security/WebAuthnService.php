<?php
declare(strict_types=1);

namespace App\Security;

/**
 * WebAuthn / Passkeys service (Face ID, Windows Hello, security keys).
 * Wraps lbuchs/WebAuthn library. Graceful fallback if library not installed.
 */
final class WebAuthnService
{
    private ?object $webAuthn = null;
    private string $rpId;
    private string $rpName;

    public function __construct(?string $rpId = null, ?string $rpName = null)
    {
        $this->rpId = $rpId ?? $this->resolveRpId();
        $this->rpName = $rpName ?? 'ERP RMI SOFULL';

        if (class_exists(\lbuchs\WebAuthn\WebAuthn::class)) {
            $this->webAuthn = new \lbuchs\WebAuthn\WebAuthn(
                $this->rpName,
                $this->rpId,
                ['none'],
                true
            );
        }
    }

    public function isAvailable(): bool
    {
        return $this->webAuthn !== null;
    }

    /**
     * Get registration options for navigator.credentials.create()
     * Returns [createArgs, challengeBase64] - store challenge in session for processCreate.
     */
    public function getCreateArgs(int $userId, string $username, string $displayName): ?array
    {
        if ($this->webAuthn === null) {
            return null;
        }
        try {
            $userIdBin = hash('sha256', 'user:' . $userId, true);
            $args = $this->webAuthn->getCreateArgs(
                $userIdBin,
                $username,
                $displayName ?: $username,
                60,
                false,
                'preferred',
                null,
                []
            );
            $challenge = $this->webAuthn->getChallenge();
            $challengeBin = $challenge instanceof \lbuchs\WebAuthn\Binary\ByteBuffer
                ? $challenge->getBinaryString()
                : (string)$challenge;
            return [
                'createArgs' => $this->webauthnToArray($args),
                'challenge' => base64_encode($challengeBin),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Process registration response, return credential data to store.
     * On failure returns ['error' => string] for debugging.
     */
    public function processCreate(string $clientDataJSON, string $attestationObject, string $challengeBase64): ?array
    {
        if ($this->webAuthn === null) {
            return ['error' => 'WebAuthn not available'];
        }
        try {
            $challenge = base64_decode($challengeBase64, true) ?: $challengeBase64;
            $data = $this->webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challenge,
                false,
                true,
                false,
                false
            );
            if ($data === null) {
                return ['error' => 'processCreate returned null'];
            }
            $credId = $data->credentialId;
            $credIdBin = $credId instanceof \lbuchs\WebAuthn\Binary\ByteBuffer
                ? $credId->getBinaryString()
                : (string)$credId;
            return [
                'credentialId' => base64_encode($credIdBin),
                'publicKey' => $data->credentialPublicKey ?? '',
                'signCount' => $data->signatureCounter ?? 0,
                'aaguid' => $data->AAGUID ?? null,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get authentication options for navigator.credentials.get()
     * Returns [getArgs, challengeBase64] - store challenge for processGet.
     */
    public function getGetArgs(array $credentialIds): ?array
    {
        if ($this->webAuthn === null) {
            return null;
        }
        try {
            $ids = [];
            foreach ($credentialIds as $id) {
                if (is_string($id)) {
                    $bin = base64_decode($id, true);
                    if ($bin !== false) {
                        $ids[] = $bin;
                    }
                }
            }
            $args = $this->webAuthn->getGetArgs(
                $ids,
                60,
                true,
                true,
                true,
                true,
                true,
                'preferred'
            );
            $challenge = $this->webAuthn->getChallenge();
            $challengeBin = $challenge instanceof \lbuchs\WebAuthn\Binary\ByteBuffer
                ? $challenge->getBinaryString()
                : (string)$challenge;
            return [
                'getArgs' => $this->webauthnToArray($args),
                'challenge' => base64_encode($challengeBin),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Process authentication response. Returns true if valid.
     */
    public function processGet(
        string $clientDataJSON,
        string $authenticatorData,
        string $signature,
        string $credentialPublicKey,
        string $challengeBase64,
        ?int $prevSignCount
    ): bool {
        if ($this->webAuthn === null) {
            return false;
        }
        try {
            $challenge = base64_decode($challengeBase64, true) ?: $challengeBase64;
            return $this->webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $credentialPublicKey,
                $challenge,
                $prevSignCount ?? 0,
                false,
                true
            ) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function webauthnToArray(object $obj): array
    {
        $json = json_encode($this->convertForJson($obj));
        $arr = $json !== false ? (array)json_decode($json, true) : [];
        return is_array($arr) ? $arr : [];
    }

    private function convertForJson(mixed $v): mixed
    {
        if ($v instanceof \lbuchs\WebAuthn\Binary\ByteBuffer) {
            return $v->getBase64Url();
        }
        if (is_object($v)) {
            $out = new \stdClass();
            foreach (get_object_vars($v) as $k => $val) {
                $out->{$k} = $this->convertForJson($val);
            }
            return $out;
        }
        if (is_array($v)) {
            return array_map([$this, 'convertForJson'], $v);
        }
        return $v;
    }

    private function resolveRpId(): string
    {
        if (function_exists('rmi_env') && ($v = rmi_env('WEBAUTHN_RP_ID', '')) !== '') {
            return trim($v);
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host = preg_replace('/:\d+$/', '', (string)$host);
        if ($host === '') {
            return 'localhost';
        }
        // Pakai host aktual agar origin match (127.0.0.1 ≠ localhost di WebAuthn)
        return $host;
    }
}
