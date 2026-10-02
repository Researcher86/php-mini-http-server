<?php

declare(strict_types=1);

namespace PhpMiniHttpServer\Http\Protocol;

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
        // The protocol name is part of the token: a bare "1.1" is not a
        // version, it is a request line from something that is not HTTP.
        $parsed = str_starts_with($raw, 'HTTP/') ? self::tryFrom(substr($raw, 5)) : null;

        if ($parsed === null) {
            throw new MalformedRequestException(sprintf('Unsupported HTTP version: %s', $raw));
        }

        return $parsed;
    }
}
