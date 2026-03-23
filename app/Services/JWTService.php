<?php

namespace App\Services;

class JWTService
{
    public static function generateToken($userId, $email, $role)
    {
        $now = time();
        $expiry = $now + JWT_EXPIRY;

        $payload = [
            'iat' => $now,
            'exp' => $expiry,
            'userId' => $userId,
            'email' => $email,
            'role' => $role
        ];

        return self::encode($payload);
    }

    public static function verifyToken($token)
    {
        try {
            $decoded = self::decode($token);
            
            if ($decoded['exp'] < time()) {
                return ['valid' => false, 'error' => 'Token expired'];
            }

            return ['valid' => true, 'data' => $decoded];
        } catch (\Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    private static function encode($payload)
    {
        $header = [
            'alg' => JWT_ALGORITHM,
            'typ' => 'JWT'
        ];

        $headerEncoded = self::base64UrlEncode(json_encode($header));
        $payloadEncoded = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            JWT_SECRET,
            true
        );

        $signatureEncoded = self::base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    private static function decode($token)
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new \Exception('Invalid token format');
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verify signature
        $expectedSignature = hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            JWT_SECRET,
            true
        );

        $expectedSignatureEncoded = self::base64UrlEncode($expectedSignature);

        if (!hash_equals($signatureEncoded, $expectedSignatureEncoded)) {
            throw new \Exception('Invalid token signature');
        }

        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid token payload');
        }

        return $payload;
    }

    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', strlen($data) % 4));
    }
}
