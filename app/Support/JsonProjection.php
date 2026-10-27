<?php

namespace App\Support;

use Closure;
use JsonException;

final class JsonProjection
{
    private const TOKENS = <<<'REGEX'
~"(?:[^"\\]++|\\.)*+"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~
REGEX;

    /** Map a JSON document without rounding numbers unrelated to the projection. */
    public static function map(string $json, Closure $project, int $options = 0): string
    {
        do {
            $prefix = '__json_number_'.bin2hex(random_bytes(16)).'_';
        } while (str_contains($json, $prefix));

        $numbers = [];
        $protected = preg_replace_callback(self::TOKENS, static function (array $match) use (&$numbers, $prefix, $options): string {
            $number = $match[0];
            try {
                if (json_encode(json_decode($number, flags: JSON_THROW_ON_ERROR), $options | JSON_THROW_ON_ERROR) === $number) {
                    return $number;
                }
            } catch (JsonException) {
                // A valid JSON number may exceed PHP's integer/float range.
            }

            $marker = '"'.$prefix.count($numbers).'"';
            $numbers[$marker] = $number;

            return $marker;
        }, $json);

        // Quoted tokens are skipped by the lexer. The collision-free markers
        // survive the structural projection and restore only original numbers.
        $mapped = $project(json_decode($protected, flags: JSON_THROW_ON_ERROR));

        return strtr(json_encode($mapped, $options | JSON_THROW_ON_ERROR), $numbers);
    }
}
