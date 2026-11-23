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
 * 首屏加载层。
 *
 * 关键路径全部内联：标记、CSS、会话判断、退场逻辑都在 body 最前面，不依赖
 * 任何外链 JS 先下载。退场要同时满足三件事：logo 的 pop 动画放完、Vue 挂载
 * 完成、主 CSS 已经应用——避免快速网络下闪一下无样式页面。
 *
 * 天空背景不再进首屏：页面 load 之后由这里再拉 /assets/site-sky.html。
 */
final class Loading
{
    private static bool $needed = false;

    private static bool $rendered = false;

    private const CSS = 'html.nomen-splash-on{overflow:hidden}'
        . '.nomen-splash{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;background:var(--color-bg,#0d1117);transition:opacity .16s ease}'
        . 'html[data-theme=light] .nomen-splash{background:var(--color-bg,#fff)}'
        . '.nomen-splash__mark{display:block;width:auto;height:88px;max-width:200px;opacity:0;transform:scale(.72);transform-origin:center;'
        . 'animation:nomen-splash-pop .72s cubic-bezier(.22,1,.36,1) forwards;filter:drop-shadow(0 16px 22px rgba(0,0,0,.18))}'
        . '.nomen-splash--out{opacity:0}'
        . 'html.nomen-boot-line{overflow:hidden;background:var(--color-bg,#0d1117)}'
        . 'html[data-theme=light].nomen-boot-line{background:var(--color-bg,#fff)}'
        . 'html.nomen-boot-line body>*{visibility:hidden}'
        . 'html.nomen-boot-line .nomen-progress{visibility:visible;display:block}'
        . 'html.nomen-boot-line .nomen-splash{display:none}'
        . '.nomen-progress{display:none;position:fixed;top:0;left:0;right:0;height:2px;z-index:2147483000;overflow:hidden}'
        . '.nomen-progress::before{content:"";position:absolute;top:0;height:100%;width:40%;left:-40%;background:var(--color-primary,#2f81f7);animation:nomen-progress 1.1s ease-in-out infinite}'
        . '@keyframes nomen-progress{0%{left:-40%}100%{left:100%}}'
        . '#app-root,#login-app,#register-app,#register-verify-app,#twofa-app,#recovery-app,#recovery-secure-app,#authorize-app,#activate-app,#admin-root{transition:opacity .16s ease}'
        . 'html.nomen-splash-on #app-root,html.nomen-splash-on #login-app,html.nomen-splash-on #register-app,html.nomen-splash-on #register-verify-app,'
        . 'html.nomen-splash-on #twofa-app,html.nomen-splash-on #recovery-app,html.nomen-splash-on #recovery-secure-app,html.nomen-splash-on #authorize-app,'
        . 'html.nomen-splash-on #activate-app,html.nomen-splash-on #admin-root{opacity:0}'
        . '@keyframes nomen-splash-pop{0%{opacity:0;transform:scale(.72)}38%{opacity:1;transform:scale(1.045)}58%{transform:scale(.985)}76%{transform:scale(1.008)}100%{opacity:1;transform:scale(1)}}'
        . '@media (prefers-reduced-motion:reduce){.nomen-splash{transition:none}.nomen-splash__mark{opacity:1;transform:none;animation:none}'
        . '.nomen-progress::before{animation:none;left:0;width:100%;opacity:.45}'
        . '#app-root,#login-app,#register-app,#register-verify-app,#twofa-app,#recovery-app,#recovery-secure-app,#authorize-app,#activate-app,#admin-root{transition:none}}';

    private const PRE = '(function(){'
        . 'var d=document.documentElement;var splash=true;'
        . 'try{var seen=sessionStorage.getItem("nomen.splash.seen")==="1";'
        . 'var type="";var nav=performance.getEntriesByType&&performance.getEntriesByType("navigation");'
        . 'if(nav&&nav.length)type=nav[0].type||"";'
        . 'splash=!seen||type==="reload";'
        . 'if(splash)sessionStorage.setItem("nomen.splash.seen","1")}catch(e){}'
        . 'if(splash)d.classList.add("nomen-splash-on");else d.classList.add("nomen-boot-line")'
        . '})();';

    private const BOOT = '(function(){'
        . 'var el=document.getElementById("nomen-splash");if(!el)return;'
        . 'var root=document.documentElement;'
        . 'var reduce=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches;'
        . 'var skyPromise=null;'
        . 'function loadSky(){if(!document.body||!document.body.classList.contains("nomen-lazy-sky"))return;'
        . 'if(document.querySelector(".site-sky"))return;if(!window.fetch||skyPromise)return skyPromise;'
        . 'skyPromise=fetch("/assets/site-sky.html",{credentials:"same-origin"}).then(function(r){return r.ok?r.text():""}).then(function(html){'
        . 'if(!html||document.querySelector(".site-sky"))return;var tpl=document.createElement("template");tpl.innerHTML=html.trim();'
        . 'var node=tpl.content.firstElementChild;if(node)document.body.insertBefore(node,document.body.firstChild)}).catch(function(){});return skyPromise}'
        . 'function scheduleSky(){var run=function(){if(window.requestIdleCallback)window.requestIdleCallback(loadSky,{timeout:2000});else window.setTimeout(loadSky,200)};'
        . 'if(document.readyState==="complete")run();else window.addEventListener("load",run,{once:true})}'
        . 'function remove(){root.classList.remove("nomen-splash-on");if(el.parentNode)el.parentNode.removeChild(el)}'
        . 'var lineMode=root.classList.contains("nomen-boot-line");'
        . 'var logo=el.querySelector(".nomen-splash__mark");'
        . 'var ready=false,logoDone=reduce||!logo||lineMode,stylesDone=false,done=false;'
        . 'function stylesReady(){var links=document.querySelectorAll("link[data-nomen-style]");'
        . 'for(var i=0;i<links.length;i++){var href=links[i].href||"";if(href&&href.indexOf(location.origin)!==0)continue;if(!links[i].sheet)return false}return true}'
        . 'function checkStyles(){if(stylesDone)return;if(stylesReady()){stylesDone=true;finish()}}'
        . 'function finish(){if(done||!ready||!stylesDone)return;if(!lineMode&&!logoDone)return;done=true;'
        . 'if(lineMode){root.classList.remove("nomen-boot-line");var line=document.getElementById("nomen-progress");'
        . 'if(line&&line.parentNode)line.parentNode.removeChild(line);'
        . 'if(el.parentNode)el.parentNode.removeChild(el);scheduleSky();return}'
        . 'if(reduce){remove();scheduleSky();return}'
        . 'root.classList.remove("nomen-splash-on");el.classList.add("nomen-splash--out");'
        . 'el.addEventListener("transitionend",remove,{once:true});setTimeout(remove,220);scheduleSky()}'
        . 'if(logo&&!reduce&&!lineMode)logo.addEventListener("animationend",function(){logoDone=true;finish()},{once:true});'
        . 'if(window.__nomenAppReady)ready=true;else window.addEventListener("nomen:app-ready",function(){ready=true;finish()},{once:true});'
        . 'var links=document.querySelectorAll("link[data-nomen-style]");'
        . 'if(!links.length)stylesDone=true;else{for(var i=0;i<links.length;i++)links[i].addEventListener("load",checkStyles,{once:true});'
        . 'var poll=setInterval(function(){if(stylesReady()){clearInterval(poll);stylesDone=true;finish()}},100);checkStyles()}'
        . 'setTimeout(function(){logoDone=true;finish()},1200);'
        . 'setTimeout(function(){ready=true;logoDone=true;stylesDone=true;finish()},30000)'
        . '})();';

    public static function enable(): void
    {
        self::$needed = true;
    }

    public static function needed(): bool
    {
        return self::$needed;
    }

    /**
     * SPA 页面上的样式表：改成 preload + onload，别让主 CSS 卡住首绘。
     * 非 SPA 页面保持原来的阻塞加载。data-nomen-style 供内联退场逻辑检查
     * 「样式是否真的已应用」，防止快速网络下提前露出无样式页面。
     */
    public static function styleTag(string $href, bool $critical = true): string
    {
        $href = htmlspecialchars($href);

        if (!self::needed()) {
            return '<link rel="stylesheet" href="' . $href . '">';
        }

        $marker = $critical ? ' data-nomen-style="1"' : '';

        return '<link rel="preload" as="style" href="' . $href . '"' . $marker . ' onload="this.onload=null;this.rel=\'stylesheet\'">'
            . '<noscript><link rel="stylesheet" href="' . $href . '"></noscript>';
    }

    /**
     * body 最前面的加载层。每个响应只输出一次。
     */
    public static function html(): string
    {
        if (self::$rendered) {
            return '';
        }
        self::$rendered = true;

        return '<style>' . self::CSS . '</style>'
            . '<noscript><style>.nomen-splash{display:none}</style></noscript>'
            . '<script>' . self::PRE . '</script>'
            . '<div id="nomen-splash" class="nomen-splash" aria-hidden="true">' . self::logoMarkup() . '</div>'
            . '<div id="nomen-progress" class="nomen-progress" aria-hidden="true"></div>'
            . '<script>' . self::BOOT . '</script>';
    }

    /**
     * 优先把品牌 logo 内联成 SVG（默认就是 Nomen 图标），避免弱网再多一个
     * 图标请求；外链 / 过大的 logo 退回 img。文件必须落在 public/ 内。
     */
    private static function logoMarkup(): string
    {
        $logo = (string)Brand::get('logo');
        $path = (string)(parse_url($logo, PHP_URL_PATH) ?: '');

        if ($path !== '' && str_ends_with(strtolower($path), '.svg')) {
            $root = realpath(dirname(__DIR__) . '/public');
            $file = realpath(dirname(__DIR__) . '/public/' . ltrim($path, '/'));

            if ($root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                $size = @filesize($file);
                if ($size !== false && $size <= 16384) {
                    $svg = (string)@file_get_contents($file);
                    $svg = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $svg);
                    $svg = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $svg);

                    if ($svg !== null && stripos($svg, '<svg') !== false) {
                        $svg = preg_replace_callback('/<svg\b([^>]*)>/i', static function (array $m): string {
                            $attrs = $m[1];
                            if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attrs, $class)) {
                                $attrs = str_replace($class[0], 'class="' . trim($class[2] . ' nomen-splash__mark') . '"', $attrs);
                            } else {
                                $attrs .= ' class="nomen-splash__mark"';
                            }
                            $attrs = preg_replace('/\baria-hidden\s*=\s*(["\']).*?\1/i', '', $attrs);
                            return '<svg' . $attrs . ' aria-hidden="true" focusable="false">';
                        }, $svg, 1);

                        if ($svg !== null) {
                            return $svg;
                        }
                    }
                }
            }
        }

        return '<img class="nomen-splash__mark" src="' . htmlspecialchars($logo) . '" alt="" width="56" height="56">';
    }
}
