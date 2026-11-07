# 翻译任务书（ locales/*.json 生成用）

> ⚠️ 2026-11-08 起母键改为英文原句（MediaWiki 式）：键 = 英文原文，`en.json` 是键的 identity 映射，
> `zh-hans.json` 是英文键→中文译文。本文件下文的「键=简体中文原文」是历史口径，流程仍可参照，
> 但键语言以 CONVENTIONS.md 现行说明为准。

目标：为 Nomen 生成 UI 翻译文件。源语言是英文（母键），英文全文翻译即键本身。

## 输入
- `locales/zh-hans.json` —— 1216 个键，键 = 简体中文原文
- `locales/en.json` —— 英文参考译文（逐键对应；新增键先补英文再补其他语言）

## 输出
- `locales/<slug>.json`，UTF-8、缩进 2、ensure_ascii=false、末尾换行
- **键集合必须与 zh-hans.json 完全一致**（一字不差，1216 个，包括带换行符的键）
- 值 = 目标语言翻译

## 硬规则
1. **占位符保真**：`{:name}`、`{:n}`、`{:max}` 这类占位符必须原样保留，数量和名字都不能变；
   译文里可以移动它们的位置（语序需要），但不能增删改名。
2. **品牌与术语不译**：CaelLab / Nomen / 虚舟实验室（→ CaelLab）/ 虚舟生态（→ CaelLab ecosystem 的当地语说法）；
   OAuth、Token、PKCE、TOTP、Client Secret / Client ID、JSON、URL、API、App、PIN、WebP、SVG、PNG、JPG、Access Token、refresh_token、redirect_uri、base64url、SHA-256、Argon2id、WebAuthn 保持英文原样。
3. **语域**：现代科技产品的通用口径 —— 面向用户的界面文案简洁自然；安全提示和邮件用礼貌体
   （de 用 Sie、fr 用 vous、ru 用 вы、es 用 tú、pt 用 você、it 用 tu、nl 用 je、ja です/ます、ko 해요체；
   有疑问时选该语言科技产品的多数派惯例）。
4. **HTML 标签与实体原样保留**：键里的 `<br>`、`&lt;`、`»`、`《》` 对应符号按目标语言习惯处理，
   标签本身不动；`《》`在译文里可用目标语言的引号/书名号替代，但要成对。
5. **中英文混合的键**（如 `code_challenge`、`Server returned`）：只翻中文部分，代码/协议词原样。
6. 键里有 `\n`（真换行）的，译文中保留换行位置。
7. 不要机翻腔：像本地产品文案，不像逐字对照。
8. **`Y年m月d日` 是 PHP `date()` 格式串**，不是普通文案：按目标语言习惯改成对应的日期格式
   （如 en `M j, Y`、de `d.m.Y`、ja `Y年n月j日`），只改格式字母，不要写成人话。
9. **以 ` · ` 开头、以 `: ` 结尾的键是设备信息拼接片段**（`' · 地点: '` / `' · 首次: '` / `' · 最近: '` /
   `' · 信任至: '` / `' · 创建: '` / `' · 上次使用: '`）：保留开头的空格、` · ` 分隔符和结尾的 `: `，
   只翻冒号前的词。
10. **有些键是被内联链接拆开的句子片段**（例如 `…都在` + `家庭` + `页面。`）：按片段翻，但要让片段
    接起来读得通；拿不准就翻成该语言里最自然的短句，不要硬凑中文语序。

## 验证（写完每个文件必须跑）
```bash
python - <<'PY'
import json, io, re, sys
slug = sys.argv[1]
zh = json.load(io.open(r'C:\Users\yunyun\Desktop\server\id\locales\zh-hans.json', encoding='utf-8'))
d = json.load(io.open(r'C:\Users\yunyun\Desktop\server\id\locales\%s.json' % slug, encoding='utf-8'))
miss = [k for k in zh if k not in d]; extra = [k for k in d if k not in zh]
bad = [k for k in zh if k in d and sorted(re.findall(r'\{:(\w+)\}', k)) != sorted(re.findall(r'\{:(\w+)\}', d[k]))]
empty = [k for k in zh if k in d and (not isinstance(d[k], str) or not d[k].strip())]
print(slug, 'miss', len(miss), 'extra', len(extra), 'ph', len(bad), 'empty', len(empty))
sys.exit(1 if (miss or extra or bad or empty) else 0)
PY
```
退出码 0 才算过。**缺键必须补齐再交**。

## 繁体中文（zh-hant）附加规则
以台湾常用资讯用语为准：帐号、設定、驗證碼、郵件（信箱）、密碼、登入、應用程式、開發者、
儀表板、訊息、隱私權政策、服務條款；「孩子」→「小孩」，「家庭」→「家庭」。
