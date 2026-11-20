<?php
declare(strict_types=1);

namespace Nomen\Src;

/**
 * 「哪些文件属于站点自己、装包时不能碰」的唯一来源。
 *
 * 同一组规则被四处使用：
 *   - UpdatePackage                自动更新
 *   - bin/nomen deploy             手工部署（本地 zip）
 *   - bin/nomen deploy:exclude     给坚持用 rsync 的人生成排除清单
 *   - code-scripts/build-dist.php  打包时哪些不进包
 *
 * 之前这几处各写一份清单：打包脚本一份、部署命令一份、运维文档一份。
 * 加一个站点私有文件要改三处，漏一处就在下一次部署时把文件删掉——
 * 2026-11-20 的 1.9.0 部署就是这么丢掉 config/home-nomenid.php 和 vendor/geoip2 的。
 *
 * 改这里的规则时只改这一处，另外四处自动跟上。
 */
final class PackageRules
{
    /**
     * 站点自己的文件：装包时永远不覆盖、不删除。
     * 都是安装器 / 后台 / 站长写出来的东西，包里的版本一定是过时的。
     */
    public const PROTECTED_FILES = [
        '.env',
        'config/installed.php',
        'config/plugins.php',
        'config/skins.php',
        'public/.user.ini',
        '.nomen-package.json',
    ];

    /** 整目录属于站点：上传的东西、插件自己的配置、站点私有页面 */
    public const PROTECTED_DIRS = [
        'data/',
        'config/plugins/',
        'public/nomen-install/',
        'public/assets/avatars/',
        'public/assets/recovery/',
        'public/assets/develops/',
    ];

    /**
     * 已经在站点上的就保留，缺的才补。
     *
     * 这一类是「程序也发、站点也可能自己改 / 自己装」的文件：
     * vendor/ 里有站长手工装的依赖（geoip2 之类），config/ 里有站点私有配置
     * （自定义首页模板 home-<slug>.php、第三方登录凭据……）。
     * 包里的版本不一定是站点要的，所以只补缺。
     */
    public const PRESERVE_IF_EXISTS = [
        'vendor/',
        'composer.json',
        'composer.lock',
        'config/',
        'public/assets/brand/',
    ];

    /**
     * 唯一跟着新版走的配置文件。
     *
     * 版本号、新配置键、新默认值都靠它下发；除此之外 config/ 全归站点。
     */
    public const CONFIG_TRACKS_RELEASE = 'config/app.php';

    /** 开发用、不进发布包的东西（和「站点私有」是两回事） */
    public const DEV_ONLY = [
        '.git',
        '.github',
        '.zcode',
        '.workbuddy',
        '.claude',
        'tests',
        'node_modules',
        'frontend',
        'docs-internal',
        'dist',
        'code-scripts',
        'data',
        '.env',
        '.gitignore',
        'AGENTS.md',
        'CLAUDE.md',
        'paths.local.php',
    ];

    /** 某个相对路径是不是站点私有的（不覆盖、不删除） */
    public static function isSitePrivate(string $rel): bool
    {
        $rel = self::norm($rel);

        foreach (self::PROTECTED_FILES as $file) {
            if ($rel === $file) {
                return true;
            }
        }

        foreach (self::PROTECTED_DIRS as $dir) {
            if (str_starts_with($rel, $dir)) {
                return true;
            }
        }

        return false;
    }

    /** 已存在时是否要保留（config/app.php 例外，它跟版本走） */
    public static function preservesIfExists(string $rel): bool
    {
        $rel = self::norm($rel);

        foreach (self::PRESERVE_IF_EXISTS as $prefix) {
            if ($rel === $prefix || str_starts_with($rel, $prefix)) {
                return $rel !== self::CONFIG_TRACKS_RELEASE;
            }
        }

        return false;
    }

    /**
     * 打包时跳过的路径（相对仓库根）。
     *
     * 只排开发文件和站点私有文件，不排 docs/ —— 文档要和代码一起发出去。
     *
     * @return string[]
     */
    public static function packExcludes(): array
    {
        return array_values(array_unique(array_merge(
            self::DEV_ONLY,
            self::PROTECTED_FILES,
            ['public/assets/avatars', 'public/assets/recovery', 'public/assets/develops', 'public/docs'],
        )));
    }

    /**
     * 手工部署（rsync）必须排除的路径。
     *
     * 只有一条特殊规则：config/ 整个排除，但 config/app.php 放行。
     * rsync 的规则是「先匹配先生效」，所以调用方必须先 include 再 exclude。
     *
     * @return array{include: string[], exclude: string[]}
     */
    public static function rsyncRules(): array
    {
        $include = ['/config/', '/config/' . basename(self::CONFIG_TRACKS_RELEASE)];
        $exclude = ['/config/*'];

        foreach (self::PROTECTED_FILES as $file) {
            if (!str_starts_with($file, 'config/')) {
                $exclude[] = '/' . $file;
            }
        }

        foreach (self::PROTECTED_DIRS as $dir) {
            if (!str_starts_with($dir, 'config/')) {
                $exclude[] = '/' . rtrim($dir, '/') . '/';
            }
        }

        // PRESERVE_IF_EXISTS 里 config/ 已被 '/config/*' 覆盖，其余在这里排
        foreach (self::PRESERVE_IF_EXISTS as $prefix) {
            if (str_starts_with($prefix, 'config/')) {
                continue;
            }
            $exclude[] = '/' . $prefix;
        }

        return [
            'include' => array_values(array_unique($include)),
            'exclude' => array_values(array_unique($exclude)),
        ];
    }

    /** rsync 命令行参数，直接拼在源和目标之间 */
    public static function rsyncArgs(): string
    {
        $rules = self::rsyncRules();
        $parts = [];
        foreach ($rules['include'] as $path) {
            $parts[] = '--include=' . escapeshellarg($path);
        }
        foreach ($rules['exclude'] as $path) {
            $parts[] = '--exclude=' . escapeshellarg($path);
        }

        return implode(' ', $parts);
    }

    private static function norm(string $rel): string
    {
        return ltrim(str_replace('\\', '/', $rel), '/');
    }
}
