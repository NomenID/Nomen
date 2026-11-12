<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 性别选项：注册、家庭页、账号恢复共用，站长用 config/genders.php 决定有哪些。
 */
final class Gender
{
    /** @var array<int, array{value: string, label: string}>|null */
    private static ?array $data = null;

    private const DEFAULTS = [
        ['value' => 'female', 'label' => 'Female'],
        ['value' => 'male', 'label' => 'Male'],
        ['value' => 'non-binary', 'label' => 'Non-binary'],
        ['value' => 'prefer-not-to-say', 'label' => 'Prefer not to say'],
    ];

    /** @return array<int, array{value: string, label: string}> */
    public static function all(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $raw = require dirname(__DIR__) . '/config/genders.php';
        $out = [];
        $seen = [];

        foreach ((array)$raw as $entry) {
            $value = is_array($entry) ? trim((string)($entry['value'] ?? '')) : trim((string)$entry);
            if ($value === '' || !preg_match('/^[a-z0-9_-]{1,32}$/', $value) || isset($seen[$value])) {
                continue;
            }
            $label = is_array($entry) ? trim((string)($entry['label'] ?? '')) : '';
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
        }

        return self::$data = $out !== [] ? $out : self::DEFAULTS;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::all(), 'value');
    }

    public static function has(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /** @return array<string, string> value => 词典键（模板还会再过一遍 t()） */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $entry) {
            $out[$entry['value']] = $entry['label'];
        }

        return $out;
    }

    /** @return array<int, array{value: string, label: string}> 已按当前语言翻译 */
    public static function translated(): array
    {
        return array_map(
            static fn(array $entry): array => [
                'value' => $entry['value'],
                'label' => I18n::tIn(I18n::locale(), $entry['label']),
            ],
            self::all()
        );
    }

    public static function label(?string $value, ?string $locale = null): string
    {
        $value = (string)$value;
        foreach (self::all() as $entry) {
            if ($entry['value'] === $value) {
                return I18n::tIn($locale ?? I18n::locale(), $entry['label']);
            }
        }

        return $value;
    }
}
