-- activity_logs.changes 放大到 MEDIUMTEXT：页面正文的对照会超过 TEXT 的 64KB。
ALTER TABLE `activity_logs` MODIFY `changes` MEDIUMTEXT;
