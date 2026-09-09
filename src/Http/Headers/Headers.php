<?php

declare(strict_types=1);

namespace App\Http\Headers;

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
     * @return array<string, string> lowercased name => value
     */
    public function all(): array
    {
        return $this->values;
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

    public function count(): int
    {
        return count($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public static function fromLines(string $head): self
    {
        $headers = new self();
        $lines = explode("\r\n", $head);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                throw new \App\Http\Protocol\MalformedRequestException(
                    sprintf('Malformed header line: %s', $line),
                );
            }

            $name = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));

            $headers->add($name, $value);
        }

        return $headers;
    }
}