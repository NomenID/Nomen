<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

/**
 * 文本对照。按行找差异，改动行再拆成词/字级别的高亮。
 * 超过上限的输入不做逐行比较，直接整段视为删除 + 新增，避免大文本把请求拖死。
 */
class TextDiff
{
    private const MAX_LINES = 1500;
    private const MAX_TOKENS = 2400;

    /**
     * @return array<int, array{type: string, old: int|null, new: int|null, text: string}>
     */
    public static function lines(string $old, string $new): array
    {
        $a = self::split($old);
        $b = self::split($new);

        if (count($a) > self::MAX_LINES || count($b) > self::MAX_LINES) {
            return self::replace($a, $b);
        }

        $out = [];
        foreach (self::ops($a, $b) as $op) {
            $out[] = [
                'type' => $op['type'],
                'old'  => $op['a'],
                'new'  => $op['b'],
                'text' => $op['type'] === 'add' ? $b[$op['b']] : $a[$op['a']],
            ];
        }

        return $out;
    }

    /**
     * 单行文本的词级对照。返回两侧的片段列表，type 为 same / del / add。
     *
     * @return array{old: array<int, array{type: string, text: string}>, new: array<int, array{type: string, text: string}>}
     */
    public static function inline(string $old, string $new): array
    {
        $a = self::tokenize($old);
        $b = self::tokenize($new);

        if (count($a) + count($b) > self::MAX_TOKENS) {
            return [
                'old' => $old === '' ? [] : [['type' => 'del', 'text' => $old]],
                'new' => $new === '' ? [] : [['type' => 'add', 'text' => $new]],
            ];
        }

        $oldSegments = [];
        $newSegments = [];

        foreach (self::ops($a, $b) as $op) {
            if ($op['type'] === 'same') {
                self::push($oldSegments, 'same', $a[$op['a']]);
                self::push($newSegments, 'same', $b[$op['b']]);
            } elseif ($op['type'] === 'del') {
                self::push($oldSegments, 'del', $a[$op['a']]);
            } else {
                self::push($newSegments, 'add', $b[$op['b']]);
            }
        }

        return ['old' => $oldSegments, 'new' => $newSegments];
    }

    /** @return string[] */
    private static function split(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** @return string[] */
    private static function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all(
            '/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]|[A-Za-z0-9_]+|\s+|./us',
            $text,
            $matches
        );

        return $matches[0] ?? [];
    }

    /**
     * Myers 差异算法。返回按顺序排列的 same / del / add。
     *
     * @return array<int, array{type: string, a: int|null, b: int|null}>
     */
    private static function ops(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n === 0) {
            return array_map(static fn(int $i): array => ['type' => 'add', 'a' => null, 'b' => $i], range(0, $m - 1));
        }
        if ($m === 0) {
            return array_map(static fn(int $i): array => ['type' => 'del', 'a' => $i, 'b' => null], range(0, $n - 1));
        }

        $max = $n + $m;
        $v = [1 => 0];
        $trace = [];

        for ($d = 0; $d <= $max; $d++) {
            $trace[] = $v;
            for ($k = -$d; $k <= $d; $k += 2) {
                if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                    $x = $v[$k + 1] ?? 0;
                } else {
                    $x = ($v[$k - 1] ?? 0) + 1;
                }

                $y = $x - $k;
                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    return self::walk($trace, $n, $m, $d);
                }
            }
        }

        return [];
    }

    /**
     * 从 Myers 的 trace 反向走回起点，得到正向的差异序列。
     *
     * @return array<int, array{type: string, a: int|null, b: int|null}>
     */
    private static function walk(array $trace, int $n, int $m, int $dEnd): array
    {
        $out = [];
        $x = $n;
        $y = $m;

        for ($d = $dEnd; $d > 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;

            if ($k === -$d || ($k !== $d && ($v[$k - 1] ?? -1) < ($v[$k + 1] ?? -1))) {
                $prevK = $k + 1;
            } else {
                $prevK = $k - 1;
            }

            $prevX = $v[$prevK] ?? 0;
            $prevY = $prevX - $prevK;

            while ($x > $prevX && $y > $prevY) {
                $x--;
                $y--;
                $out[] = ['type' => 'same', 'a' => $x, 'b' => $y];
            }

            if ($x === $prevX) {
                $y--;
                $out[] = ['type' => 'add', 'a' => null, 'b' => $y];
            } else {
                $x--;
                $out[] = ['type' => 'del', 'a' => $x, 'b' => null];
            }
        }

        while ($x > 0 && $y > 0) {
            $x--;
            $y--;
            $out[] = ['type' => 'same', 'a' => $x, 'b' => $y];
        }
        while ($x > 0) {
            $x--;
            $out[] = ['type' => 'del', 'a' => $x, 'b' => null];
        }
        while ($y > 0) {
            $y--;
            $out[] = ['type' => 'add', 'a' => null, 'b' => $y];
        }

        return array_reverse($out);
    }

    /** @return array<int, array{type: string, old: int|null, new: int|null, text: string}> */
    private static function replace(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $i => $line) {
            $out[] = ['type' => 'del', 'old' => $i, 'new' => null, 'text' => $line];
        }
        foreach ($b as $i => $line) {
            $out[] = ['type' => 'add', 'old' => null, 'new' => $i, 'text' => $line];
        }

        return $out;
    }

    private static function push(array &$segments, string $type, string $text): void
    {
        $last = count($segments) - 1;
        if ($last >= 0 && $segments[$last]['type'] === $type) {
            $segments[$last]['text'] .= $text;
            return;
        }

        $segments[] = ['type' => $type, 'text' => $text];
    }
}
