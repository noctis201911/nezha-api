<?php

namespace App\Support\Security;

class CspReportSanitizer
{
    /**
     * Return zero or more allow-listed report records that are safe to log.
     */
    public function sanitize(array $payload, string $mediaType): array
    {
        if ($mediaType === 'application/csp-report') {
            $body = $payload['csp-report'] ?? null;

            return is_array($body) ? [$this->sanitizeBody($body, false)] : [];
        }

        if ($mediaType !== 'application/reports+json' || ! array_is_list($payload)) {
            return [];
        }

        $reports = [];
        foreach (array_slice($payload, 0, 20) as $candidate) {
            if (! is_array($candidate)
                || ($candidate['type'] ?? null) !== 'csp-violation'
                || ! is_array($candidate['body'] ?? null)) {
                continue;
            }
            $reports[] = $this->sanitizeBody($candidate['body'], true);
        }

        return $reports;
    }

    private function sanitizeBody(array $body, bool $modern): array
    {
        $key = static fn (string $legacy, string $reporting): string => $modern ? $reporting : $legacy;
        $disposition = strtolower((string) ($body[$key('disposition', 'disposition')] ?? 'report'));

        return [
            'report_type' => 'csp-violation',
            'document' => $this->documentRoute($body[$key('document-uri', 'documentURL')] ?? null),
            'effective_directive' => $this->directive($body[$key('effective-directive', 'effectiveDirective')] ?? null),
            'blocked_source' => $this->source($body[$key('blocked-uri', 'blockedURL')] ?? null),
            'source_origin' => $this->origin($body[$key('source-file', 'sourceFile')] ?? null),
            'disposition' => in_array($disposition, ['report', 'enforce'], true) ? $disposition : 'unknown',
            'status_code' => $this->boundedInteger($body[$key('status-code', 'statusCode')] ?? null, 100, 599),
            'line' => $this->boundedInteger($body[$key('line-number', 'lineNumber')] ?? null, 0, 10_000_000),
            'column' => $this->boundedInteger($body[$key('column-number', 'columnNumber')] ?? null, 0, 10_000_000),
        ];
    }

    private function documentRoute(mixed $value): string
    {
        $parts = $this->httpUrlParts($value);
        if ($parts === null) {
            return 'unknown';
        }

        $path = (string) ($parts['path'] ?? '/');
        $route = match (true) {
            preg_match('#^/checkout(?:/|$)#', $path) === 1 => '/checkout',
            preg_match('#^/info(?:/|$)#', $path) === 1 => '/info',
            preg_match('#^/tracking(?:/|$)#', $path) === 1 => '/tracking',
            default => '/redacted',
        };

        return $this->originFromParts($parts).$route;
    }

    private function directive(mixed $value): string
    {
        $directive = strtolower(trim(is_string($value) ? $value : ''));

        return preg_match('/^[a-z][a-z0-9-]{0,63}$/', $directive) === 1
            ? $directive
            : 'unknown';
    }

    private function source(mixed $value): string
    {
        $source = strtolower(trim(is_string($value) ? $value : ''));
        if (in_array($source, ['inline', 'eval', 'self'], true)) {
            return $source;
        }
        if (str_starts_with($source, 'data:')) {
            return 'data:';
        }
        if (str_starts_with($source, 'blob:')) {
            return 'blob:';
        }

        return $this->origin($value);
    }

    private function origin(mixed $value): string
    {
        $parts = $this->httpUrlParts($value);

        return $parts === null ? 'unknown' : $this->originFromParts($parts);
    }

    private function httpUrlParts(mixed $value): ?array
    {
        if (! is_string($value) || strlen($value) > 4096) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $parts;
    }

    private function originFromParts(array $parts): string
    {
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer >= $minimum && $integer <= $maximum ? $integer : 0;
    }
}
