<?php

declare(strict_types=1);

namespace App\Http\Headers;

use App\Http\Protocol\MalformedRequestException;

/**
 * An HTTP header collection shared by requests and responses.
 *
 * Header names are case-insensitive on the wire (Host == host == HOST), so
 * lookups go through a lowercased index. Repeated lines of the same name
 * are comma-joined, which is how HTTP combines most duplicated headers.
 *
 * Original casing is kept per header name so re-encoding still looks
 * natural to a human reader.
 */
final class Headers
{
    /** @var array<string, string> lowercased name => value */
    private array $values = [];

    /** @var array<string, string> lowercased name => original casing */
    private array $names = [];

    public function set(string $name, string $value): void
    {
        $key = strtolower($name);

        $this->values[$key] = $value;
        $this->names[$key] = $name;
    }

    /**
     * Append a value, comma-joining with any existing value.
     */
    public function add(string $name, string $value): void
    {
        $key = strtolower($name);
        $existing = $this->values[$key] ?? null;

        $this->set($name, $existing === null ? $value : $existing . ', ' . $value);
    }

    public function get(string $name): ?string
    {
        return $this->values[strtolower($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->values[strtolower($name)]);
    }

    /**
     * @return array<string, string> original name => value
     */
    public function normalized(): array
    {
        $normalized = [];

        foreach (array_keys($this->values) as $key) {
            $normalized[$this->names[$key]] = $this->values[$key];
        }

        return $normalized;
    }

    /**
     * Parse a header block — the lines between the request line and the
     * blank line — into a collection.
     *
     * Every rule here is a rule about what to refuse. A lenient header
     * parser is how two machines end up disagreeing about the request they
     * both just handled, and that disagreement is request smuggling.
     */
    public static function fromLines(string $head): self
    {
        $headers = new self();

        foreach (explode("\r\n", $head) as $line) {
            if ($line === '') {
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                throw new MalformedRequestException(sprintf('Malformed header line: %s', $line));
            }

            $name = substr($line, 0, $colon);

            // Not trimmed, checked. RFC 7230 3.2.4 requires a server to
            // reject whitespace between the name and the colon outright,
            // rather than quietly trimming it into a valid-looking name.
            // The same check catches an obsolete folded continuation line
            // ("  more value") that happens to contain a colon and would
            // otherwise read as a header of its own.
            if (!self::isToken($name)) {
                throw new MalformedRequestException(sprintf('Malformed header name: %s', $name));
            }

            // Only spaces and tabs are stripped — the optional whitespace
            // the grammar allows around a value. Anything else that shows
            // up at an edge is a control character, and it is refused
            // below rather than silently removed.
            $value = trim(substr($line, $colon + 1), " \t");

            if (!self::isFieldValue($value)) {
                throw new MalformedRequestException(sprintf('Malformed value for header %s', $name));
            }

            $headers->add($name, $value);
        }

        return $headers;
    }

    /**
     * A header name is a token: visible ASCII minus the delimiter
     * characters. Notably that excludes space and tab, which is the whole
     * point of checking.
     */
    private static function isToken(string $name): bool
    {
        return $name !== '' && preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) === 1;
    }

    /**
     * A header value may hold visible characters, spaces, tabs and bytes
     * above 0x7F (obs-text, which is how non-ASCII arrives). It may not
     * hold control characters: a CR or LF in there is a line ending
     * somebody downstream will act on, and a NUL is a string terminator in
     * every language this request might be forwarded to.
     */
    private static function isFieldValue(string $value): bool
    {
        return preg_match('/^[\t\x20-\x7E\x80-\xFF]*$/', $value) === 1;
    }
}