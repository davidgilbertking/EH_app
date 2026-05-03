<?php

declare(strict_types=1);

if (!function_exists('request_parse_body')) {
    /**
     * Polyfill for PHP 8.4 request_parse_body() on PHP 8.3 runtimes.
     *
     * Symfony HttpFoundation calls this function for PUT/PATCH/DELETE/QUERY.
     * We only need urlencoded/json support for current app endpoints.
     *
     * @param array<string, mixed>|null $options
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    function request_parse_body(?array $options = null): array
    {
        unset($options);

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $raw = file_get_contents('php://input') ?: '';

        if ($raw === '') {
            return [$_POST ?? [], $_FILES ?? []];
        }

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $data = [];
            parse_str($raw, $data);
            return [is_array($data) ? $data : [], $_FILES ?? []];
        }

        if (
            stripos($contentType, 'application/json') !== false
            || stripos($contentType, '+json') !== false
        ) {
            $decoded = json_decode($raw, true);
            return [is_array($decoded) ? $decoded : [], $_FILES ?? []];
        }

        return [$_POST ?? [], $_FILES ?? []];
    }
}
