<?php

declare(strict_types=1);

namespace App\Http\Protocol;

/**
 * HTTP protocol versions the server speaks.
 */
enum HttpVersion: string
{
    case HTTP_1_0 = '1.0';
    case HTTP_1_1 = '1.1';

    public function wire(): string
    {
        return 'HTTP/' . $this->value;
    }

    /**
     * @throws MalformedRequestException when the wire version is unsupported
     */
    public static function fromWire(string $raw): self
    {
        $prefix = 'HTTP/';
        $version = str_starts_with($raw, $prefix) ? substr($raw, strlen($prefix)) : $raw;

        $parsed = self::tryFrom($version);

        if ($parsed === null) {
            throw new MalformedRequestException(sprintf('Unsupported HTTP version: %s', $raw));
        }

        return $parsed;
    }
}
