<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Unid;

test('UNID 是 26 位可校验的 ULID', static function (): void {
    $unid = Unid::generate();

    assertSame(26, strlen($unid));
    assertTrue(Unid::isValid($unid), '自己生成的要能过校验');
    assertMatches('/^[0-9A-HJKMNP-TV-Z]{26}$/', $unid);
});

test('UNID 按时间有序，且不重复', static function (): void {
    $previous = '';
    $seen = [];

    for ($i = 0; $i < 200; $i++) {
        $unid = Unid::generate();
        // 前 10 位是毫秒时间戳；同一毫秒内的后 16 位是随机的，不保证递增
        assertTrue(strcmp(substr($unid, 0, 10), substr($previous, 0, 10)) >= 0, '时间部分不该倒退');
        assertTrue(!isset($seen[$unid]), '重复了：' . $unid);
        $seen[$unid] = true;
        $previous = $unid;
    }

    assertSame(200, count($seen));
});

test('UNID 拒绝小写和截断值', static function (): void {
    assertTrue(Unid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    assertTrue(!Unid::isValid('01arz3ndektsv4rrffq69g5fav'), '小写必须拒绝');
    assertTrue(!Unid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FA'), '25 位必须拒绝');
    assertTrue(!Unid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAVV'), '27 位必须拒绝');
    assertTrue(!Unid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FAI'), 'I 不在字母表里');
});

test('Unid::backfill 只补空号', static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, unid TEXT)');
    $pdo->exec("INSERT INTO users (id, unid) VALUES (1, NULL), (2, ''), (3, '01ARZ3NDEKTSV4RRFFQ69G5FAV')");

    assertSame(2, Unid::backfill($pdo));

    $rows = $pdo->query('SELECT id, unid FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $rows[3], '已有编号不能动');
    assertTrue(Unid::isValid((string)$rows[1]));
    assertTrue(Unid::isValid((string)$rows[2]));
});
