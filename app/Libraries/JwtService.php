<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private static string $key;
    private static string $algo = 'HS256';

    private static function init(): void
    {
        self::$key = getenv('JWT_SECRET') ?: 'CHANGE_THIS_SECRET';
    }

    public static function generate(array $payload): string
    {
        self::init();

        $time = time();

        $token = array_merge($payload, [
            'iat' => $time,
            'exp' => $time + (60 * 60 * 24) // 24h
        ]);

        return JWT::encode($token, self::$key, self::$algo);
    }

    public static function validate(string $token)
    {
        self::init();
        return JWT::decode($token, new Key(self::$key, self::$algo));
    }
}
