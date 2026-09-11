<?php

declare(strict_types=1);

namespace App\Router;

use Closure;
use InvalidArgumentException;

/**
 * One registered route pattern plus its compiled matcher.
 *
 * The pattern uses {name} placeholders, one per path segment:
 *
 *     /users/{id}  matches  /users/42        → id = "42"
 *     /users/{id}  matches  /users/42/orders → no (a segment is one {…})
 *
 * Compilation turns the pattern into a regex with named groups, so the
 * matcher itself stays regex-free: preg_match extracts the parameters and
 * the handler receives them as an associative array.
 */
final readonly class Route
{
    public string $regex;

    public function __construct(
        public string $pattern,
        public Closure $handler,
    ) {
        $this->regex = self::compile($pattern);
    }

    /**
     * Compile "{name}" placeholders into a full-anchored regex, escaping
     * every literal character first so dots, slashes and the like match
     * literally instead of as regex syntax.
     *
     * @throws InvalidArgumentException when the pattern repeats a name —
     *         duplicate capture groups would silently break the regex
     */
    private static function compile(string $pattern): string
    {
        $quoted = preg_quote($pattern, '~');

        $names = [];

        $regex = preg_replace_callback(
            '/\\\{(\w+)\\\}/',
            static function (array $match) use (&$names, $pattern): string {
                $name = $match[1];

                if (isset($names[$name])) {
                    throw new InvalidArgumentException(sprintf(
                        'Duplicate route parameter {%s} in "%s".',
                        $name,
                        $pattern,
                    ));
                }

                $names[$name] = true;

                return '(?P<' . $name . '>[^/]+)';
            },
            $quoted,
        );

        if ($regex === null) {
            return '~^$~';
        }

        return '~^' . $regex . '$~';
    }
}
