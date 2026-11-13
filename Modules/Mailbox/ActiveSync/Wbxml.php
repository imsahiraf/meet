<?php

namespace Modules\Mailbox\ActiveSync;

use RuntimeException;

/**
 * WBXML (binary XML) as Exchange ActiveSync uses it: version 1.3, public id 1, UTF-8, no string table, no attributes.
 * Elements are named "Page:Tag" (for example "AirSync:Sync"); see CodePages for the token tables.
 *
 * A node is an array: ['n' => 'Page:Tag', 'c' => [child nodes], 't' => text or null, 'o' => true when the text is opaque (binary)].
 */
final class Wbxml
{
    private const SWITCH_PAGE = 0x00, END = 0x01, STR_I = 0x03, OPAQUE = 0xC3;

    private static ?array $byName = null;

    // ---------------- building nodes ----------------

    public static function el(string $name, array $children = []): array
    {
        return ['n' => $name, 'c' => array_values(array_filter($children, fn ($c) => $c !== null)), 't' => null, 'o' => false];
    }

    public static function text(string $name, string|int $text): array
    {
        return ['n' => $name, 'c' => [], 't' => (string) $text, 'o' => false];
    }

    public static function opaque(string $name, string $bytes): array
    {
        return ['n' => $name, 'c' => [], 't' => $bytes, 'o' => true];
    }

    // ---------------- reading nodes ----------------

    /** First child with this name (direct children only). */
    public static function child(?array $node, string $name): ?array
    {
        foreach ($node['c'] ?? [] as $c) {
            if ($c['n'] === $name) {
                return $c;
            }
        }

        return null;
    }

    /** @return array<int, array> all direct children with this name */
    public static function children(?array $node, string $name): array
    {
        return array_values(array_filter($node['c'] ?? [], fn ($c) => $c['n'] === $name));
    }

    /** Text of a child, or of a path of children: value($node, 'AirSync:Options', 'AirSync:FilterType'). */
    public static function value(?array $node, string ...$path): ?string
    {
        foreach ($path as $p) {
            $node = self::child($node, $p);
            if ($node === null) {
                return null;
            }
        }

        return $node['t'] ?? ($node['c'] === [] ? '' : null);
    }

    // ---------------- decode ----------------

    public static function decode(string $bin): ?array
    {
        if ($bin === '') {
            return null;
        }
        $pos = 0;
        $len = strlen($bin);
        $byte = function () use ($bin, &$pos, $len): int {
            if ($pos >= $len) {
                throw new RuntimeException('WBXML ended early.');
            }

            return ord($bin[$pos++]);
        };
        $mbInt = function () use ($byte): int {
            $v = 0;
            for ($i = 0; $i < 5; $i++) {
                $b = $byte();
                $v = ($v << 7) | ($b & 0x7F);
                if (!($b & 0x80)) {
                    return $v;
                }
            }
            throw new RuntimeException('WBXML number too long.');
        };
        $byte(); // version
        $mbInt(); // public id
        $mbInt(); // charset (UTF-8 expected)
        $strTable = $mbInt();
        $pos += $strTable;

        $page = 0;
        $root = null;
        $stack = [];
        while ($pos < $len) {
            $t = $byte();
            if ($t === self::SWITCH_PAGE) {
                $page = $byte();
                continue;
            }
            if ($t === self::END) {
                $node = array_pop($stack);
                if ($node === null) {
                    throw new RuntimeException('WBXML END without an element.');
                }
                if ($stack) {
                    $stack[count($stack) - 1]['c'][] = $node;
                } else {
                    $root = $node;
                }
                continue;
            }
            if ($t === self::STR_I) {
                $end = strpos($bin, "\0", $pos);
                if ($end === false || !$stack) {
                    throw new RuntimeException('Bad WBXML string.');
                }
                $stack[count($stack) - 1]['t'] = ($stack[count($stack) - 1]['t'] ?? '') . substr($bin, $pos, $end - $pos);
                $pos = $end + 1;
                continue;
            }
            if ($t === self::OPAQUE) {
                $n = $mbInt();
                if (!$stack || $pos + $n > $len) {
                    throw new RuntimeException('Bad WBXML opaque data.');
                }
                $stack[count($stack) - 1]['t'] = substr($bin, $pos, $n);
                $stack[count($stack) - 1]['o'] = true;
                $pos += $n;
                continue;
            }
            if ($t & 0x80) {
                throw new RuntimeException('WBXML attributes are not used by ActiveSync.');
            }
            $token = $t & 0x3F;
            [$ns, $tags] = CodePages::PAGES[$page] ?? [null, []];
            $name = ($ns ?? ('Page' . $page)) . ':' . ($tags[$token] ?? ('Unknown' . $token));
            $node = ['n' => $name, 'c' => [], 't' => null, 'o' => false];
            if ($t & 0x40) {
                $stack[] = $node;
                if (count($stack) > 64) {
                    throw new RuntimeException('WBXML nested too deep.');
                }
            } elseif ($stack) {
                $stack[count($stack) - 1]['c'][] = $node;
            } else {
                $root = $node;
            }
        }
        if ($stack) {
            throw new RuntimeException('WBXML element not closed.');
        }

        return $root;
    }

    // ---------------- encode ----------------

    public static function encode(array $root): string
    {
        $page = 0;
        $out = "\x03\x01\x6A\x00";
        self::write($root, $out, $page);

        return $out;
    }

    private static function write(array $node, string &$out, int &$page): void
    {
        [$p, $token] = self::token($node['n']);
        if ($p !== $page) {
            $out .= chr(self::SWITCH_PAGE) . chr($p);
            $page = $p;
        }
        $hasText = $node['t'] !== null && $node['t'] !== '';
        if (!$hasText && !$node['c']) {
            $out .= chr($token); // empty element

            return;
        }
        $out .= chr($token | 0x40);
        if ($hasText) {
            if ($node['o']) {
                $out .= chr(self::OPAQUE) . self::mbInt(strlen($node['t'])) . $node['t'];
            } else {
                $out .= chr(self::STR_I) . str_replace("\0", '', $node['t']) . "\0";
            }
        }
        foreach ($node['c'] as $c) {
            self::write($c, $out, $page);
        }
        $out .= chr(self::END);
    }

    private static function token(string $name): array
    {
        if (self::$byName === null) {
            self::$byName = [];
            foreach (CodePages::PAGES as $p => [$ns, $tags]) {
                foreach ($tags as $tok => $tag) {
                    self::$byName[$ns . ':' . $tag] = [$p, $tok];
                }
            }
        }

        return self::$byName[$name] ?? throw new RuntimeException('Unknown ActiveSync element ' . $name);
    }

    private static function mbInt(int $v): string
    {
        $bytes = chr($v & 0x7F);
        while ($v >>= 7) {
            $bytes = chr(0x80 | ($v & 0x7F)) . $bytes;
        }

        return $bytes;
    }
}
