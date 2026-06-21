<?php
/**
 * 每日60s新闻插件
 *
 * 提供「每天60秒读懂世界」新闻早报功能，支持短代码 [daily60s] 嵌入任意文章或页面。
 * 支持多API备用切换、API Token、自定义标题配色、图片本地化、懒加载、SEO结构化数据、
 * 缓存并发锁、定时自动预热等高级功能。
 *
 * @package Daily60s
 * @author yuege.
 * @version 2.0.0
 * @link https://beicb.top
 */

namespace TypechoPlugin\Daily60s;

use Typecho\Plugin\PluginInterface;
use Typecho\Plugin\Exception as PluginException;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    /**
     * 默认 API 地址（返回 JSON 格式数据）
     */
    const DEFAULT_API = 'https://60s.viki.moe/v2/60s?encoding=json';

    /**
     * 默认图片 API 地址（直接返回图片）
     */
    const DEFAULT_IMAGE_API = 'https://60s.viki.moe/v2/60s?encoding=image';

    /**
     * 缓存目录（相对于站点根目录）
     */
    const CACHE_DIR = 'usr/uploads/60s/cache/';

    /**
     * 默认缓存保留天数
     */
    const DEFAULT_CACHE_DAYS = 15;

    /**
     * 并发锁超时时间（秒）
     */
    const LOCK_TIMEOUT = 30;

    /**
     * 是否允许在证书环境缺失时降级为不校验证书
     */
    const ALLOW_INSECURE_SSL_FALLBACK = true;

    /**
     * 插件激活
     */
    public static function activate()
    {
        // 创建缓存目录
        self::initCacheDir();

        // 注册内容解析钩子，处理 [daily60s] 短代码
        \Typecho\Plugin::factory('Widget\Base\Contents')->contentEx = array(
            'TypechoPlugin\Daily60s\Plugin', 'parseShortcode'
        );

        // 注册摘要解析钩子，避免摘要中出现短代码
        \Typecho\Plugin::factory('Widget\Base\Contents')->excerptEx = array(
            'TypechoPlugin\Daily60s\Plugin', 'parseShortcodeExcerpt'
        );

        // 注册 Action（用于手动刷新、定时预热）
        \Utils\Helper::addAction('daily60s', 'TypechoPlugin\Daily60s\Action');

        // 注册缓存管理面板
        \Utils\Helper::addPanel(3, 'Daily60s/Panel.php', '每日60s缓存管理', '管理每日60s新闻缓存', 'administrator');

        return _t('每日60s新闻插件已启用。请在插件设置中配置参数，在文章或页面中使用短代码 [daily60s] 显示每日新闻。');
    }

    /**
     * 插件禁用
     */
    public static function deactivate()
    {
        \Utils\Helper::removeAction('daily60s');
        \Utils\Helper::removePanel(3, 'Daily60s/Panel.php');
        return _t('每日60s新闻插件已禁用，缓存文件保留在 usr/uploads/60s/cache/ 目录。');
    }

    /**
     * 插件配置面板
     */
    public static function config(Form $form)
    {
        // ==================== 配置导出 / 导入 ====================
        $form->addItem(new HtmlBlock(self::renderExportImportHtml()));

        // ==================== 使用方法说明（右侧浮动面板） ====================
        $usageGuide = new Text(
            'usageGuide',
            null,
            '',
            _t('使用方法'),
            _t('<div id="daily60s-guide-content" style="display:none;">
            <strong style="font-size:15px;color:#1976d2;">快速使用指南</strong><br><br>
            <strong>一、基本使用</strong><br>
            1. 在任意文章或页面的正文中输入短代码 <code>[daily60s]</code>，保存后访问该页面即可显示每日60s新闻<br>
            2. 首次访问时自动从 API 获取当天新闻并缓存，之后全天读取缓存（快速响应）<br>
            3. 每天首次访问自动获取新一天的新闻，无需手动操作<br><br>
            <strong>二、缓存说明</strong><br>
            1. 所有缓存文件（JSON 数据和图片）统一存放在 <code>usr/uploads/60s/cache/</code> 目录<br>
            2. 缓存目录不存在时会<strong>自动创建</strong>，无需手动建目录<br>
            3. 超过「缓存保留天数」的旧缓存会自动清理（默认15天）<br>
            4. 可在后台「控制台」→「每日60s缓存管理」中手动刷新或清理缓存<br><br>
            <strong>三、定时自动更新（可选）</strong><br>
            1. 在下方开启「定时自动预热」开关<br>
            2. 登录宝塔面板 → 计划任务 → 添加任务<br>
            3. 任务类型选择「访问URL」，URL 填写下方显示的预热地址<br>
            4. 设置执行周期为每天，时间建议设为 06:00<br>
            5. 配置后每天凌晨自动获取新闻，用户访问时直接读缓存<br><br>
            <strong>四、API 设置说明</strong><br>
            1. 默认 API（60s.viki.moe）免费无需 token，直接使用即可<br>
            2. 如默认 API 不稳定，可在「备用 API 地址」中添加备用源<br>
            3. 如使用需要 token 的 API（如 ALAPI），在「API Token」中填写<br>
            4. API 返回类型可选：JSON（文本+图片）、仅文本、图片<br><br>
            <strong>五、显示自定义</strong><br>
            1. 可自定义标题文案、副标题、标题栏配色<br>
            2. 可选择显示样式（完整/仅图片/仅文字）<br>
            3. 可开启图片本地化、懒加载、SEO 结构化数据等高级功能
            </div>
            <script>
            (function() {
                function initGuide() {
                    var content = document.getElementById("daily60s-guide-content");
                    if (!content) { setTimeout(initGuide, 100); return; }

                    // 隐藏原始表单项
                    var optionEl = content.closest(".typecho-option");
                    if (optionEl) { optionEl.style.display = "none"; }

                    // 创建浮动按钮
                    var btn = document.createElement("button");
                    btn.id = "daily60s-guide-btn";
                    btn.innerHTML = "📖 使用指南";
                    btn.style.cssText = "position:absolute;right:50px;top:180px;padding:10px 18px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:25px;cursor:pointer;z-index:10001;font-size:14px;box-shadow:0 4px 15px rgba(102,126,234,0.4);transition:transform 0.3s;visibility:hidden;";

                    // 创建浮动面板
                    var panel = document.createElement("div");
                    panel.id = "daily60s-guide-panel";
                    panel.style.cssText = "position:absolute;right:10px;top:230px;width:340px;max-height:60vh;overflow-y:auto;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.15);z-index:10000;display:none;padding:20px 20px 40px;font-size:13px;line-height:1.8;color:#333;border:1px solid #e0e0e0;";
                    panel.innerHTML = content.innerHTML;

                    // 关闭按钮
                    var closeBtn = document.createElement("div");
                    closeBtn.innerHTML = "✕";
                    closeBtn.style.cssText = "position:absolute;top:10px;right:15px;cursor:pointer;font-size:18px;color:#999;font-weight:bold;";
                    closeBtn.onclick = function() { panel.style.display = "none"; };
                    panel.appendChild(closeBtn);

                    btn.onclick = function(event) {
                        event.stopPropagation();
                        panel.style.display = panel.style.display === "none" ? "block" : "none";
                    };
                    panel.onclick = function(event) { event.stopPropagation(); };
                    document.addEventListener("click", function() {
                        panel.style.display = "none";
                    });
                    btn.onmouseenter = function() { btn.style.transform = "translateY(-2px)"; };
                    btn.onmouseleave = function() { btn.style.transform = "translateY(0)"; };

                    document.body.appendChild(btn);
                    document.body.appendChild(panel);

                    // 动态对齐：找到「主 API 地址」标签，定位完成后再显示按钮，避免刷新时下沉跳动
                    setTimeout(function() {
                        var labels = document.querySelectorAll(".typecho-option label");
                        for (var i = 0; i < labels.length; i++) {
                            if (labels[i].textContent.indexOf("API") !== -1 && labels[i].textContent.indexOf("地址") !== -1) {
                                var rect = labels[i].getBoundingClientRect();
                                var top = rect.top + window.pageYOffset;
                                btn.style.top = top + "px";
                                panel.style.top = (top + btn.offsetHeight + 10) + "px";
                                break;
                            }
                        }
                        btn.style.visibility = "visible";
                    }, 200);
                }
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", initGuide);
                } else {
                    initGuide();
                }
            })();
            </script>')
        );
        $form->addInput($usageGuide);

        // ==================== API 设置 ====================
        $apiUrl = new Text(
            'apiUrl',
            null,
            self::DEFAULT_API,
            _t('主 API 地址'),
            _t('用于获取每日60秒新闻数据。留空则使用默认 API：<br><code>' . self::DEFAULT_API . '</code><br>
            如果您使用的 API 返回的是图片格式，请在下方「API 返回类型」中选择「图片」。')
        );
        $form->addInput($apiUrl);

        $backupApiUrls = new Textarea(
            'backupApiUrls',
            null,
            '',
            _t('备用 API 地址（多 API 自动切换）'),
            _t('当主 API 请求失败时，会按顺序尝试这些备用 API。<br>
            <strong>每行一个</strong>备用 API 地址，留空则不使用备用。<br>
            示例：<br>
            <code>https://api.viki.moe/v2/60s?encoding=json</code><br>
            <code>https://v2.alapi.cn/api/zaobao?token=您的token&format=json</code><br><br>
            <strong>说明：</strong>备用 API 的返回格式需与主 API 一致（同为 JSON/文本 或同为图片）。')
        );
        $form->addInput($backupApiUrls);

        $apiToken = new Text(
            'apiToken',
            null,
            '',
            _t('API Token（可选）'),
            _t('部分 API（如 ALAPI）需要 token 才能访问。<br>
            <strong>使用方法：</strong><br>
            1. 如果 API 地址中包含 <code>{token}</code> 占位符，会自动替换为这里填写的 token。<br>
            &nbsp;&nbsp;&nbsp;&nbsp;例如：<code>https://v3.alapi.cn/api/zaobao?token={token}&format=json</code><br>
            2. 如果 API 地址不含 <code>{token}</code>，则 token 会以 <code>&token=xxx</code> 形式追加到 URL 末尾。<br>
            3. 使用默认 API（60s.viki.moe）无需填写 token。')
        );
        $form->addInput($apiToken);

        $apiType = new Radio(
            'apiType',
            array(
                'json' => _t('JSON 格式（返回新闻文本和图片）'),
                'text' => _t('仅文本格式（只返回新闻文本，不含图片）'),
                'image' => _t('图片格式（直接返回早报图片）')
            ),
            'json',
            _t('API 返回类型'),
            _t('请根据您填写的 API 地址选择对应的返回类型。<br>
            <strong>JSON 格式：</strong>API 返回 JSON 数据，包含新闻列表、微语和图片地址，页面显示完整内容。<br>
            <strong>仅文本格式：</strong>API 返回 JSON 数据，但只显示新闻列表和微语，不显示图片。适合不需要图片或 API 不返回图片的场景。<br>
            <strong>图片格式：</strong>API 直接返回一张早报图片，页面只显示该图片。')
        );
        $form->addInput($apiType);

        // ==================== 显示设置 ====================
        $customTitle = new Text(
            'customTitle',
            null,
            '每天60秒读懂世界',
            _t('自定义标题文案'),
            _t('显示在新闻上方的标题文字，默认为「每天60秒读懂世界」。可改为任意文字，如「每日早报」「今日新闻速览」等。')
        );
        $form->addInput($customTitle);

        $customSubtitle = new Text(
            'customSubtitle',
            null,
            '',
            _t('自定义副标题（可选）'),
            _t('显示在标题下方的副标题文字，留空则不显示。如「每天1分钟，了解天下大事」。')
        );
        $form->addInput($customSubtitle);

        $headerColor1 = new Text(
            'headerColor1',
            null,
            '#667eea',
            _t('标题栏渐变起始色'),
            _t('标题栏背景渐变的起始颜色，支持十六进制颜色值（如 #667eea）。<br>可使用 <a href="https://www.google.com/search?q=color+picker" target="_blank">在线取色器</a> 选择颜色。')
        );
        $form->addInput($headerColor1);

        $headerColor2 = new Text(
            'headerColor2',
            null,
            '#764ba2',
            _t('标题栏渐变结束色'),
            _t('标题栏背景渐变的结束颜色，支持十六进制颜色值（如 #764ba2）。')
        );
        $form->addInput($headerColor2);

        $displayStyle = new Select(
            'displayStyle',
            array(
                'full' => _t('完整显示（图片 + 新闻列表 + 微语）'),
                'image' => _t('仅显示图片'),
                'text' => _t('仅显示新闻列表和微语')
            ),
            'full',
            _t('显示样式'),
            _t('选择新闻在页面中的显示样式。<br>
            <strong>注意：</strong>仅在 API 返回类型为「JSON 格式」时有效。当 API 返回类型为「仅文本格式」时，此项被忽略（自动为仅文字）；当为「图片格式」时，此项也不生效。')
        );
        $form->addInput($displayStyle);

        $showDate = new Radio(
            'showDate',
            array(
                '1' => _t('显示'),
                '0' => _t('不显示')
            ),
            '1',
            _t('显示日期标题'),
            _t('是否在新闻上方显示标题和日期。')
        );
        $form->addInput($showDate);

        // ==================== 图片设置 ====================
        $localizeImage = new Radio(
            'localizeImage',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭')
            ),
            '0',
            _t('图片本地化（JSON 模式）'),
            _t('开启后，JSON 模式下 API 返回的早报图片会下载到本地缓存目录，避免外链失效。<br>
            <strong>说明：</strong>仅对 JSON 模式有效，图片模式本身就是下载到本地。关闭则直接引用 API 返回的外链地址。')
        );
        $form->addInput($localizeImage);

        $lazyLoad = new Radio(
            'lazyLoad',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭')
            ),
            '0',
            _t('图片懒加载'),
            _t('开启后，新闻图片在滚动到可视区域时才加载，提升页面加载速度。<br>
            <strong>功能说明：</strong>使用浏览器原生 <code>loading="lazy"</code> 属性，并配合 IntersectionObserver 实现完整的懒加载方案，包含加载占位图和淡入动画效果。')
        );
        $form->addInput($lazyLoad);

        // ==================== SEO 设置 ====================
        $structuredData = new Radio(
            'structuredData',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭')
            ),
            '1',
            _t('结构化数据（SEO）'),
            _t('开启后，会在页面中输出 JSON-LD 结构化数据（NewsArticle 类型），有利于搜索引擎收录和展示。<br>
            <strong>说明：</strong>结构化数据输出在页面 HTML 源码中（<code>&lt;script type="application/ld+json"&gt;</code> 标签），用户在浏览器中查看源码可以看到，搜索引擎爬虫会读取这些数据。不会影响页面显示效果。')
        );
        $form->addInput($structuredData);

        // ==================== 缓存设置 ====================
        $cacheDays = new Text(
            'cacheDays',
            null,
            (string) self::DEFAULT_CACHE_DAYS,
            _t('缓存保留天数'),
            _t('缓存文件自动清理的天数，超过此天数的缓存将被删除。<br>
            <strong>默认 15 天</strong>。设置 0 则不自动清理。<br>
            <strong>缓存目录：</strong><code>' . self::CACHE_DIR . '</code><br>
            所有缓存文件（JSON 数据和图片）统一存放在此目录。<br><br>
            <div style="background:#fff3e0;border-left:4px solid #ff9800;padding:10px 15px;border-radius:4px;margin-top:8px;">
            <strong>⚠️ 如果新闻或图片加载不出来（404 错误）</strong><br>
            原因：缓存目录无法自动创建，通常是 <code>usr/uploads/60s/</code> 目录所有者不是 Web 服务器用户。<br>
            解决方法：在宝塔面板文件管理中，将 <code>usr/uploads/60s/</code> 目录的所有者设为 <code>www</code>，权限设为 <code>755</code>（需应用于子目录）。<br>
            或 SSH 执行：<code>chown -R www:www usr/uploads/60s/ &amp;&amp; chmod -R 755 usr/uploads/60s/</code>
            </div>')
        );
        $form->addInput($cacheDays);

        // ==================== 定时预热设置 ====================
        $autoPreheat = new Radio(
            'autoPreheat',
            array(
                '1' => _t('开启'),
                '0' => _t('关闭（默认）')
            ),
            '0',
            _t('定时自动预热'),
            _t('开启后，可通过定时任务在每天凌晨自动获取当天新闻并缓存，用户访问时直接读缓存，无需等待。<br>
            <strong>配置方法（宝塔面板）：</strong><br>
            1. 登录宝塔面板 → 计划任务 → 添加任务<br>
            2. 任务类型选择「访问URL」<br>
            3. 任务名称填写「每日60s新闻预热」<br>
            4. 执行周期选择每天，时间设为 06:00 或您需要的时间<br>
            5. URL 示例：<code>https://example.com/action/daily60s?do=preheat</code>（请将 example.com 替换为您的域名）<br>
            <strong>或使用 Shell 命令：</strong><br>
            <code>curl -s "https://example.com/action/daily60s?do=preheat"</code>（请将 example.com 替换为您的域名）<br><br>
            <strong>说明：</strong>此开关仅作为标记，实际定时任务需在宝塔面板中配置。关闭后定时任务访问将返回提示信息。')
        );
        $form->addInput($autoPreheat);
    }

    /**
     * 个人用户配置
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 获取缓存目录的文件系统绝对路径
     * 兼容 __TYPECHO_ROOT_DIR__ 是否带尾部斜杠的情况
     */
    public static function getCachePath()
    {
        return rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . self::CACHE_DIR;
    }

    /**
     * 获取缓存目录的 URL 地址
     */
    public static function getCacheUrl()
    {
        $siteUrl = \Typecho\Widget::widget('Widget\Options')->siteUrl;
        return rtrim($siteUrl, '/') . '/' . self::CACHE_DIR;
    }

    /**
     * 初始化缓存目录
     */
    public static function initCacheDir()
    {
        $cachePath = self::getCachePath();
        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0755, true);
        }
        if (is_dir($cachePath)) {
            $indexFile = $cachePath . 'index.html';
            if (!file_exists($indexFile)) {
                @file_put_contents($indexFile, '');
            }
            return is_writable($cachePath);
        }
        return false;
    }

    /**
     * 渲染配置导出/导入 HTML
     */
    private static function renderExportImportHtml()
    {
        return <<<HTML
<div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 20px;margin:20px 0;">
    <h3 style="margin:0 0 12px;font-size:16px;color:#333;">配置导出 / 导入</h3>
    <p style="font-size:13px;color:#666;margin:0 0 12px;">导出当前插件配置到 JSON 文件，或从 JSON 文件导入配置。方便备份和迁移。</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button type="button" onclick="Daily60sExportConfig()" style="height:34px;padding:0 16px;background:#28a745;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;line-height:34px;box-sizing:border-box;vertical-align:middle;">导出配置</button>
        <button type="button" onclick="document.getElementById('daily60s-import-file').click()" style="height:34px;padding:0 16px;background:#007bff;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;line-height:34px;box-sizing:border-box;vertical-align:middle;">导入配置</button>
        <input type="file" id="daily60s-import-file" accept=".json" onchange="Daily60sImportConfig(this)" style="display:none;">
        <span id="daily60s-import-msg" style="font-size:13px;color:#666;line-height:34px;vertical-align:middle;"></span>
    </div>
</div>
<script>
function Daily60sGetConfigFields() {
    return {
        text: ['apiUrl', 'backupApiUrls', 'apiToken', 'customTitle', 'customSubtitle', 'headerColor1', 'headerColor2', 'cacheDays'],
        select: ['displayStyle'],
        radio: ['apiType', 'showDate', 'localizeImage', 'lazyLoad', 'structuredData', 'autoPreheat']
    };
}

function Daily60sExportConfig() {
    var config = {};
    var fields = Daily60sGetConfigFields();
    fields.text.forEach(function(name) {
        var el = document.getElementById(name) || document.querySelector('[name="' + name + '"]');
        if (el) config[name] = el.value;
    });
    fields.select.forEach(function(name) {
        var el = document.getElementById(name) || document.querySelector('[name="' + name + '"]');
        if (el) config[name] = el.value;
    });
    fields.radio.forEach(function(name) {
        var checked = document.querySelector('input[name="' + name + '"]:checked');
        if (checked) config[name] = checked.value;
    });
    var json = JSON.stringify(config, null, 2);
    var blob = new Blob([json], {type: 'application/json'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'daily60s-config-' + new Date().toISOString().slice(0,10) + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

function Daily60sImportConfig(input) {
    var file = input.files[0];
    if (!file) return;
    var reader = new FileReader();
    var msg = document.getElementById('daily60s-import-msg');
    reader.onload = function(e) {
        try {
            var data = JSON.parse(e.target.result);
            var config = data.config || data;
            var fields = Daily60sGetConfigFields();
            var count = 0;
            fields.text.forEach(function(name) {
                if (config[name] === undefined) return;
                var el = document.getElementById(name) || document.querySelector('[name="' + name + '"]');
                if (el) { el.value = config[name]; count++; }
            });
            fields.select.forEach(function(name) {
                if (config[name] === undefined) return;
                var el = document.getElementById(name) || document.querySelector('[name="' + name + '"]');
                if (el) { el.value = config[name]; count++; }
            });
            fields.radio.forEach(function(name) {
                if (config[name] === undefined) return;
                var radio = document.querySelector('input[name="' + name + '"][value="' + config[name] + '"]');
                if (radio) { radio.checked = true; count++; }
            });
            msg.style.color = '#28a745';
            msg.textContent = '已导入 ' + count + ' 项配置，请点击下方保存设置生效。';
        } catch(err) {
            msg.style.color = '#dc3545';
            msg.textContent = '导入失败：' + err.message;
        }
        input.value = '';
    };
    reader.readAsText(file);
}
</script>
HTML;
    }

    /**
     * 获取插件配置
     */
    public static function getConfig($key = null, $default = null)
    {
        try {
            $options = \Typecho\Widget::widget('Widget\Options');
            $pluginConfig = $options->plugin('Daily60s');
        } catch (\Exception $e) {
            if ($key === null) {
                return new \stdClass();
            }
            return $default;
        }

        if ($key === null) {
            return $pluginConfig;
        }

        $value = isset($pluginConfig->{$key}) ? $pluginConfig->{$key} : $default;

        if ($value === null || $value === '') {
            switch ($key) {
                case 'apiUrl':
                    return self::DEFAULT_API;
                case 'apiType':
                    return 'json';
                case 'cacheDays':
                    return self::DEFAULT_CACHE_DAYS;
                case 'displayStyle':
                    return 'full';
                case 'showDate':
                    return '1';
                case 'customTitle':
                    return '每天60秒读懂世界';
                case 'headerColor1':
                    return '#667eea';
                case 'headerColor2':
                    return '#764ba2';
                case 'localizeImage':
                    return '0';
                case 'lazyLoad':
                    return '0';
                case 'structuredData':
                    return '1';
                case 'autoPreheat':
                    return '0';
                default:
                    return $default;
            }
        }

        return $value;
    }

    /**
     * 解析短代码（用于摘要，移除短代码）
     */
    public static function parseShortcodeExcerpt($content, $widget, $lastResult)
    {
        if (preg_match('/\[daily60s\]/i', $content)) {
            return self::replaceShortcodeWithExcerpt($content);
        }

        $content = empty($lastResult) ? $content : $lastResult;
        return self::stripGeneratedOutput($content);
    }

    /**
     * 解析短代码（用于正文）
     */
    public static function parseShortcode($content, $widget, $lastResult)
    {
        $content = empty($lastResult) ? $content : $lastResult;

        if (!preg_match('/\[daily60s\]/i', $content)) {
            return $content;
        }

        if (is_object($widget) && method_exists($widget, 'is') && !$widget->is('single')) {
            return self::replaceShortcodeWithExcerpt($content);
        }

        $html = self::renderNews();
        $content = preg_replace('/\[daily60s\]/i', $html, $content);

        return $content;
    }

    /**
     * 列表页摘要中用标题和副标题替换短代码，避免输出样式代码
     */
    private static function replaceShortcodeWithExcerpt($content)
    {
        $title = self::getConfig('customTitle', '每天60秒读懂世界');
        $subtitle = self::getConfig('customSubtitle', '');
        $summary = $title ? $title : '每日60s新闻';
        if (!empty($subtitle)) {
            $summary .= ' ' . $subtitle;
        }

        $imageHtml = self::getExcerptImageHtml();
        return self::stripGeneratedOutput(preg_replace('/\[daily60s\]/i', $imageHtml . $summary, $content));
    }

    /**
     * 获取列表页摘要用的新闻图片
     */
    private static function getExcerptImageHtml()
    {
        $imageUrl = self::getExcerptImageUrl();
        if (empty($imageUrl)) {
            return '';
        }
        return '<p class="daily-60s-excerpt-image"><img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="每日60s新闻早报" loading="lazy"></p>';
    }

    /**
     * 获取列表页摘要用的新闻图片地址
     */
    private static function getExcerptImageUrl()
    {
        $apiType = self::getConfig('apiType', 'json');
        $today = date('Y-m-d');
        $cachePath = self::getCachePath();
        $cacheUrl = self::getCacheUrl();

        self::initCacheDir();

        if ($apiType === 'image') {
            $cacheFile = $cachePath . $today . '.jpg';
            if (is_file($cacheFile) && filesize($cacheFile) > 0) {
                return $cacheUrl . $today . '.jpg';
            }
            $imageData = self::fetchImageWithLock();
            if ($imageData !== false && self::isImageData($imageData)) {
                $writeResult = @file_put_contents($cacheFile, $imageData);
                if ($writeResult !== false && $writeResult > 0) {
                    return $cacheUrl . $today . '.jpg';
                }
            }
            $recentImage = self::getRecentCacheImage($cachePath);
            return $recentImage ? $cacheUrl . $recentImage : '';
        }

        if ($apiType === 'text') {
            return '';
        }

        $newsData = self::getNewsData();
        if (!$newsData || !isset($newsData['data']['image']) || empty($newsData['data']['image'])) {
            return '';
        }

        $imageUrl = $newsData['data']['image'];
        $localizeImage = self::getConfig('localizeImage', '0');
        if ($localizeImage == '1') {
            $newsDate = isset($newsData['data']['date']) ? $newsData['data']['date'] : $today;
            $localImageUrl = self::localizeImage($imageUrl, $newsDate);
            if ($localImageUrl) {
                return $localImageUrl;
            }
        }

        return $imageUrl;
    }

    /**
     * 移除每日60s生成的样式和脚本，保留文章原文内容
     */
    private static function stripGeneratedOutput($content)
    {
        $content = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $content);
        $content = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $content);
        $content = preg_replace('/<div\s+class="daily-60s-wrap"[\s\S]*?<\/div>\s*/i', '', $content);
        $content = preg_replace('/\.daily-60s-[^{]+\{[^}]*\}/i', '', $content);
        return trim($content);
    }

    /**
     * 渲染新闻内容
     */
    public static function renderNews()
    {
        $apiType = self::getConfig('apiType', 'json');
        $displayStyle = self::getConfig('displayStyle', 'full');
        $showDate = self::getConfig('showDate', '1');

        // 确保缓存目录存在且可写
        if (!self::initCacheDir()) {
            return self::renderError(
                '缓存目录创建失败或不可写。<br><br>' .
                '<strong>请手动创建目录并设置权限：</strong><br>' .
                '1. 在服务器上创建目录：<code>usr/uploads/60s/cache/</code><br>' .
                '2. 设置目录权限为 755 或 777<br>' .
                '3. 确保目录所有者为 Web 服务器用户（如 www 或 nginx）<br>' .
                '4. 或在宝塔面板文件管理中手动创建 cache 文件夹并设置权限为 777'
            );
        }

        // 自动清理过期缓存
        self::cleanExpiredCache();

        if ($apiType === 'image') {
            return self::renderImageApi();
        } elseif ($apiType === 'text') {
            // 仅文本模式：强制不显示图片
            return self::renderJsonApi('text', $showDate);
        } else {
            return self::renderJsonApi($displayStyle, $showDate);
        }
    }

    /**
     * 处理 API URL 中的 Token
     */
    public static function processApiUrl($url)
    {
        $token = self::getConfig('apiToken', '');

        if (!empty($token)) {
            if (strpos($url, '{token}') !== false) {
                $url = str_replace('{token}', $token, $url);
            } else {
                $separator = (strpos($url, '?') !== false) ? '&' : '?';
                $url .= $separator . 'token=' . urlencode($token);
            }
        }

        return $url;
    }

    /**
     * 获取所有 API 地址列表（主 API + 备用 API）
     */
    public static function getApiUrlList()
    {
        $apiType = self::getConfig('apiType', 'json');
        $primaryApi = self::getConfig('apiUrl', self::DEFAULT_API);

        // 如果是图片类型且使用默认 API，替换为图片 API
        if ($apiType === 'image' && $primaryApi === self::DEFAULT_API) {
            $primaryApi = self::DEFAULT_IMAGE_API;
        }

        $primaryApi = self::processApiUrl($primaryApi);
        $urlList = array($primaryApi);

        // 解析备用 API
        $backupApiUrls = self::getConfig('backupApiUrls', '');
        if (!empty($backupApiUrls)) {
            $backupUrls = explode("\n", str_replace("\r", '', $backupApiUrls));
            foreach ($backupUrls as $backupUrl) {
                $backupUrl = trim($backupUrl);
                if (!empty($backupUrl)) {
                    // 图片类型且是默认 JSON API，替换为图片 API
                    if ($apiType === 'image' && $backupUrl === self::DEFAULT_API) {
                        $backupUrl = self::DEFAULT_IMAGE_API;
                    }
                    $urlList[] = self::processApiUrl($backupUrl);
                }
            }
        }

        return $urlList;
    }

    /**
     * 渲染图片 API 类型
     */
    public static function renderImageApi()
    {
        $today = date('Y-m-d');
        $cachePath = self::getCachePath();
        $cacheUrl = self::getCacheUrl();
        $cacheFile = $cachePath . $today . '.jpg';
        $lazyLoad = self::getConfig('lazyLoad', '0');
        $showDate = self::getConfig('showDate', '1');
        $customTitle = self::getConfig('customTitle', '每天60秒读懂世界');
        $customSubtitle = self::getConfig('customSubtitle', '');

        // 确保缓存目录存在且可写（renderNews 已检查，此处为直接调用时的保障）
        self::initCacheDir();

        // 检查本地缓存
        $imgUrl = '';
        if (is_file($cacheFile) && filesize($cacheFile) > 0) {
            $imgUrl = $cacheUrl . $today . '.jpg';
        } else {
            // 获取图片并缓存（带并发锁）
            $imageData = self::fetchImageWithLock();
            if ($imageData !== false) {
                // 写入缓存文件，检查返回值
                $writeResult = @file_put_contents($cacheFile, $imageData);
                if ($writeResult !== false && $writeResult > 0) {
                    $imgUrl = $cacheUrl . $today . '.jpg';
                } else {
                    // 写入失败，尝试读取最近缓存
                    $recentImage = self::getRecentCacheImage($cachePath);
                    if ($recentImage) {
                        $imgUrl = $cacheUrl . $recentImage;
                    } else {
                        return self::renderError('图片缓存写入失败，请检查目录权限：' . self::CACHE_DIR);
                    }
                }
            } else {
                // API 获取失败，尝试读取最近缓存
                $recentImage = self::getRecentCacheImage($cachePath);
                if ($recentImage) {
                    $imgUrl = $cacheUrl . $recentImage;
                } else {
                    return self::renderError('图片获取失败，请检查 API 地址或网络连接');
                }
            }
        }

        $html = '<div class="daily-60s-wrap">';

        // 标题
        if ($showDate == '1') {
            $html .= '<div class="daily-60s-header">';
            $html .= '<h2>' . htmlspecialchars($customTitle) . '</h2>';
            if (!empty($customSubtitle)) {
                $html .= '<div class="subtitle">' . htmlspecialchars($customSubtitle) . '</div>';
            }
            $html .= '<div class="date">' . $today . '</div>';
            $html .= '</div>';
        }

        // 图片
        $html .= '<div class="daily-60s-image">';
        if ($lazyLoad == '1') {
            $html .= '<img class="daily-60s-lazy" src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxIiBoZWlnaHQ9IjEiPjxyZWN0IGZpbGw9IiNmNWY1ZjUiIHdpZHRoPSIxIiBoZWlnaHQ9IjEiLz48L3N2Zz4=" data-src="' . htmlspecialchars($imgUrl) . '" alt="每日60s新闻早报 ' . $today . '" loading="lazy">';
        } else {
            $html .= '<img src="' . htmlspecialchars($imgUrl) . '" alt="每日60s新闻早报 ' . $today . '">';
        }
        $html .= '</div>';
        $html .= '</div>';
        $html .= self::renderStyles();

        // 懒加载脚本（仅在开启懒加载时输出）
        if ($lazyLoad == '1') {
            $html .= self::renderLazyLoadScript();
        }

        return $html;
    }

    /**
     * 渲染 JSON API 类型
     */
    public static function renderJsonApi($displayStyle, $showDate)
    {
        $newsData = self::getNewsData();

        if (!$newsData || !isset($newsData['data'])) {
            return self::renderError();
        }

        $data = $newsData['data'];
        $newsDate = isset($data['date']) ? $data['date'] : date('Y-m-d');
        $newsList = isset($data['news']) ? $data['news'] : array();
        $weiyu = isset($data['weiyu']) ? $data['weiyu'] : '';
        $imageUrl = isset($data['image']) ? $data['image'] : '';
        $tip = isset($data['tip']) ? $data['tip'] : '';

        // JSON 完整显示/仅图片模式下，优先使用当天本地图片缓存；开启图片本地化时则主动下载到本地
        if ($displayStyle != 'text' && !empty($imageUrl)) {
            $localizeImage = self::getConfig('localizeImage', '0');
            if ($localizeImage == '1') {
                $localImageUrl = self::localizeImage($imageUrl, $newsDate);
                if ($localImageUrl) {
                    $imageUrl = $localImageUrl;
                }
            } else {
                $cachedImageUrl = self::cacheJsonImage($imageUrl, $newsDate);
                if ($cachedImageUrl) {
                    $imageUrl = $cachedImageUrl;
                }
            }
        }

        $lazyLoad = self::getConfig('lazyLoad', '0');
        $customTitle = self::getConfig('customTitle', '每天60秒读懂世界');
        $customSubtitle = self::getConfig('customSubtitle', '');

        $html = '<div class="daily-60s-wrap">';

        // 日期标题
        if ($showDate == '1') {
            $html .= '<div class="daily-60s-header">';
            $html .= '<h2>' . htmlspecialchars($customTitle) . '</h2>';
            if (!empty($customSubtitle)) {
                $html .= '<div class="subtitle">' . htmlspecialchars($customSubtitle) . '</div>';
            }
            $html .= '<div class="date">' . htmlspecialchars($newsDate) . '</div>';
            $html .= '</div>';
        }

        // 图片
        if (($displayStyle == 'full' || $displayStyle == 'image') && !empty($imageUrl)) {
            $html .= '<div class="daily-60s-image">';
            if ($lazyLoad == '1') {
                $html .= '<img class="daily-60s-lazy" src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxIiBoZWlnaHQ9IjEiPjxyZWN0IGZpbGw9IiNmNWY1ZjUiIHdpZHRoPSIxIiBoZWlnaHQ9IjEiLz48L3N2Zz4=" data-src="' . htmlspecialchars($imageUrl) . '" alt="每日60s新闻早报图片 ' . htmlspecialchars($newsDate) . '" loading="lazy">';
            } else {
                $html .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="每日60s新闻早报图片 ' . htmlspecialchars($newsDate) . '">';
            }
            $html .= '</div>';
        }

        // 新闻列表
        if (($displayStyle == 'full' || $displayStyle == 'text') && !empty($newsList) && is_array($newsList)) {
            $html .= '<div class="daily-60s-news-list">';
            $html .= '<ol>';
            foreach ($newsList as $news) {
                $html .= '<li>' . htmlspecialchars($news) . '</li>';
            }
            $html .= '</ol>';
            $html .= '</div>';
        }

        // 每日微语
        if (!empty($weiyu)) {
            $html .= '<div class="daily-60s-weiyu">';
            $html .= '<strong>每日微语：</strong>' . htmlspecialchars($weiyu);
            $html .= '</div>';
        }

        // 提示信息
        if (!empty($tip)) {
            $html .= '<div class="daily-60s-tip">' . htmlspecialchars($tip) . '</div>';
        }

        $html .= '</div>';
        $html .= self::renderStyles();

        // 结构化数据（SEO）
        $structuredData = self::getConfig('structuredData', '1');
        if ($structuredData == '1') {
            $html .= self::renderStructuredData($customTitle, $newsDate, $newsList, $weiyu);
        }

        // 懒加载脚本
        if ($lazyLoad == '1') {
            $html .= self::renderLazyLoadScript();
        }

        return $html;
    }

    /**
     * 图片本地化：下载图片到本地缓存目录
     */
    public static function localizeImage($imageUrl, $date)
    {
        $cachePath = self::getCachePath();
        $cacheUrl = self::getCacheUrl();
        $localFileName = $date . '_img.jpg';
        $localFile = $cachePath . $localFileName;

        // 如果本地已存在，直接返回
        if (is_file($localFile) && filesize($localFile) > 0) {
            return $cacheUrl . $localFileName;
        }

        // 下载图片
        $imageData = self::fetchUrl($imageUrl);
        if ($imageData !== false && strlen($imageData) > 100 && self::isImageData($imageData)) {
            $writeResult = @file_put_contents($localFile, $imageData);
            if ($writeResult !== false && $writeResult > 0) {
                return $cacheUrl . $localFileName;
            }
        }

        return false;
    }

    /**
     * JSON 模式下缓存当天图片，未开启图片本地化时也可复用，避免图文不一致
     */
    public static function cacheJsonImage($imageUrl, $date)
    {
        $cachePath = self::getCachePath();
        $cacheUrl = self::getCacheUrl();
        $localFileName = $date . '.jpg';
        $localFile = $cachePath . $localFileName;

        if (is_file($localFile) && filesize($localFile) > 0) {
            return $cacheUrl . $localFileName;
        }

        $imageData = self::fetchUrl($imageUrl);
        if ($imageData !== false && strlen($imageData) > 100 && self::isImageData($imageData)) {
            $writeResult = @file_put_contents($localFile, $imageData);
            if ($writeResult !== false && $writeResult > 0) {
                return $cacheUrl . $localFileName;
            }
        }

        return false;
    }

    /**
     * 渲染结构化数据（JSON-LD）
     */
    public static function renderStructuredData($title, $date, $newsList, $weiyu)
    {
        $articleBody = '';
        if (!empty($newsList) && is_array($newsList)) {
            $articleBody = implode("\n", $newsList);
        }
        if (!empty($weiyu)) {
            $articleBody .= "\n每日微语：" . $weiyu;
        }

        $siteUrl = \Typecho\Widget::widget('Widget\Options')->siteUrl;
        $siteName = \Typecho\Widget::widget('Widget\Options')->title;

        $structuredData = array(
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $title . ' ' . $date,
            'datePublished' => $date,
            'dateModified' => $date,
            'articleBody' => $articleBody,
            'publisher' => array(
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => $siteUrl
            ),
            'description' => mb_substr($articleBody, 0, 150, 'UTF-8')
        );

        return '<script type="application/ld+json">' . json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }

    /**
     * 获取新闻数据（带缓存和并发锁）
     */
    public static function getNewsData()
    {
        $cachePath = self::getCachePath();
        $today = date('Y-m-d');
        $cacheFile = $cachePath . $today . '.json';

        // 检查当天缓存
        if (is_file($cacheFile)) {
            $cacheContent = @file_get_contents($cacheFile);
            if ($cacheContent) {
                $data = json_decode($cacheContent, true);
                if ($data && isset($data['data'])) {
                    return $data;
                }
            }
        }

        // 并发锁检查
        $lockFile = $cachePath . '.lock';
        if (self::isLockActive($lockFile)) {
            // 其他进程正在获取，等待后读取缓存
            usleep(500000); // 等待 0.5 秒
            if (is_file($cacheFile)) {
                $cacheContent = @file_get_contents($cacheFile);
                if ($cacheContent) {
                    $data = json_decode($cacheContent, true);
                    if ($data && isset($data['data'])) {
                        return $data;
                    }
                }
            }
            // 仍然没有缓存，返回最近缓存
            return self::getRecentCacheData($cachePath);
        }

        // 创建锁
        self::createLock($lockFile);

        try {
            // 从 API 获取（多 API 自动切换）
            $apiUrlList = self::getApiUrlList();
            $response = false;

            foreach ($apiUrlList as $apiUrl) {
                $response = self::fetchUrl($apiUrl);
                if ($response !== false) {
                    $data = json_decode($response, true);
                    if ($data && isset($data['code']) && $data['code'] == 200 && isset($data['data'])) {
                        // 写入缓存
                        @file_put_contents($cacheFile, $response);
                        return $data;
                    }
                }
            }

            // 所有 API 都失败，尝试读取最近缓存
            return self::getRecentCacheData($cachePath);
        } finally {
            // 释放锁
            self::releaseLock($lockFile);
        }
    }

    /**
     * 获取图片数据（带并发锁，多 API 切换）
     */
    public static function fetchImageWithLock()
    {
        $cachePath = self::getCachePath();
        $lockFile = $cachePath . '.lock';
        $today = date('Y-m-d');
        $cacheFile = $cachePath . $today . '.jpg';

        // 如果锁活跃，等待其他请求完成（最多等待10秒）
        if (self::isLockActive($lockFile)) {
            $waited = 0;
            while (self::isLockActive($lockFile) && $waited < 10) {
                usleep(500000); // 0.5秒
                $waited += 0.5;
                // 检查缓存文件是否已生成
                if (is_file($cacheFile) && filesize($cacheFile) > 0) {
                    return @file_get_contents($cacheFile);
                }
            }
            // 等待超时，锁仍活跃
            if (self::isLockActive($lockFile)) {
                return false;
            }
        }

        // 获取锁
        self::createLock($lockFile);

        try {
            // 双重检查：等待期间缓存可能已被其他请求创建
            if (is_file($cacheFile) && filesize($cacheFile) > 0) {
                return @file_get_contents($cacheFile);
            }

            $apiUrlList = self::getApiUrlList();
            foreach ($apiUrlList as $apiUrl) {
                $imageData = self::fetchUrl($apiUrl);
                // 验证数据有效且确实是图片
                if ($imageData !== false && strlen($imageData) > 100 && self::isImageData($imageData)) {
                    return $imageData;
                }
            }
            return false;
        } finally {
            self::releaseLock($lockFile);
        }
    }

    /**
     * 验证数据是否为有效图片（通过文件头魔数判断）
     */
    public static function isImageData($data)
    {
        if (strlen($data) < 12) {
            return false;
        }
        // JPEG: FF D8 FF
        if (substr($data, 0, 3) === "\xFF\xD8\xFF") {
            return true;
        }
        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (substr($data, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
            return true;
        }
        // GIF: 47 49 46 38
        if (substr($data, 0, 4) === "\x47\x49\x46\x38") {
            return true;
        }
        // WebP: RIFF....WEBP
        if (substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return true;
        }
        return false;
    }

    /**
     * 检查锁是否活跃
     */
    public static function isLockActive($lockFile)
    {
        if (!file_exists($lockFile)) {
            return false;
        }
        $lockTime = (int) @file_get_contents($lockFile);
        return (time() - $lockTime) < self::LOCK_TIMEOUT;
    }

    /**
     * 创建锁
     */
    public static function createLock($lockFile)
    {
        @file_put_contents($lockFile, time());
    }

    /**
     * 释放锁
     */
    public static function releaseLock($lockFile)
    {
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * 使用 cURL 或 file_get_contents 获取 URL 内容
     */
    public static function fetchUrl($url)
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Typecho/1.3.0 Daily60s/2.0.0';
        $isHttps = stripos($url, 'https://') === 0;
        $allowInsecureFallback = self::ALLOW_INSECURE_SSL_FALLBACK && $isHttps;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $isHttps);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $isHttps ? 2 : 0);
            curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $sslProblem = false;

            if (($response === false || $httpCode < 200 || $httpCode >= 300) && $allowInsecureFallback) {
                $curlErrorNo = curl_errno($ch);
                $sslProblem = in_array($curlErrorNo, array(35, 51, 58, 60, 77, 83), true);
            }

            if ($sslProblem) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }

            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                return $response;
            }
            return false;
        }

        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 15,
                'user_agent' => $userAgent,
                'follow_location' => true,
                'max_redirects' => 3
            ),
            'ssl' => array(
                'verify_peer' => $isHttps,
                'verify_peer_name' => $isHttps
            )
        ));

        $response = @file_get_contents($url, false, $context);
        if ($response !== false) {
            return $response;
        }

        if (!$allowInsecureFallback) {
            return false;
        }

        $fallbackContext = stream_context_create(array(
            'http' => array(
                'timeout' => 15,
                'user_agent' => $userAgent,
                'follow_location' => true,
                'max_redirects' => 3
            ),
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false
            )
        ));

        $fallbackResponse = @file_get_contents($url, false, $fallbackContext);
        return $fallbackResponse !== false ? $fallbackResponse : false;
    }

    /**
     * 获取最近一天的 JSON 缓存数据
     */
    public static function getRecentCacheData($cachePath)
    {
        if (!is_dir($cachePath)) {
            return null;
        }

        $files = glob($cachePath . '*.json');
        if (empty($files)) {
            return null;
        }

        rsort($files);

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $data = json_decode($content, true);
                if ($data && isset($data['data'])) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * 获取最近的缓存图片文件名
     */
    public static function getRecentCacheImage($cachePath)
    {
        if (!is_dir($cachePath)) {
            return null;
        }

        $files = glob($cachePath . '*.jpg');
        if (empty($files)) {
            $files = glob($cachePath . '*.png');
        }

        if (empty($files)) {
            return null;
        }

        rsort($files);
        return basename($files[0]);
    }

    /**
     * 清理过期缓存
     */
    public static function cleanExpiredCache()
    {
        $cacheDays = intval(self::getConfig('cacheDays', self::DEFAULT_CACHE_DAYS));

        if ($cacheDays <= 0) {
            return;
        }

        $cachePath = self::getCachePath();
        if (!is_dir($cachePath)) {
            return;
        }

        $expireTime = time() - ($cacheDays * 86400);

        // 清理 JSON 缓存
        $jsonFiles = glob($cachePath . '*.json');
        if (!empty($jsonFiles)) {
            foreach ($jsonFiles as $file) {
                if (filemtime($file) < $expireTime) {
                    @unlink($file);
                }
            }
        }

        // 清理图片缓存
        $imageExtensions = array('*.jpg', '*.jpeg', '*.png', '*.webp');
        foreach ($imageExtensions as $pattern) {
            $imageFiles = glob($cachePath . $pattern);
            if (!empty($imageFiles)) {
                foreach ($imageFiles as $file) {
                    if (filemtime($file) < $expireTime) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    /**
     * 手动刷新缓存：清空所有缓存文件
     */
    public static function refreshCache()
    {
        $cachePath = self::getCachePath();
        $deletedCount = 0;

        if (is_dir($cachePath)) {
            $files = glob($cachePath . '*');
            if (!empty($files)) {
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== 'index.html') {
                        if (@unlink($file)) {
                            $deletedCount++;
                        }
                    }
                }
            }
        }

        return $deletedCount;
    }

    /**
     * 获取缓存统计信息
     */
    public static function getCacheStats()
    {
        $cachePath = self::getCachePath();
        $stats = array(
            'fileCount' => 0,
            'totalSize' => 0,
            'totalSizeText' => '0 B',
            'jsonCount' => 0,
            'imageCount' => 0,
            'lastUpdate' => '无',
            'files' => array()
        );

        if (!is_dir($cachePath)) {
            return $stats;
        }

        $files = glob($cachePath . '*');
        if (empty($files)) {
            return $stats;
        }

        $latestTime = 0;

        foreach ($files as $file) {
            if (!is_file($file) || basename($file) === 'index.html' || basename($file) === '.lock') {
                continue;
            }

            $fileSize = filesize($file);
            $fileMtime = filemtime($file);
            $fileName = basename($file);

            $stats['fileCount']++;
            $stats['totalSize'] += $fileSize;

            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            if ($ext === 'json') {
                $stats['jsonCount']++;
            } else {
                $stats['imageCount']++;
            }

            if ($fileMtime > $latestTime) {
                $latestTime = $fileMtime;
            }

            $stats['files'][] = array(
                'name' => $fileName,
                'size' => $fileSize,
                'sizeText' => self::formatSize($fileSize),
                'time' => $fileMtime,
                'timeText' => date('Y-m-d H:i:s', $fileMtime)
            );
        }

        if ($latestTime > 0) {
            $stats['lastUpdate'] = date('Y-m-d H:i:s', $latestTime);
        }

        $stats['totalSizeText'] = self::formatSize($stats['totalSize']);

        // 按时间倒序排列
        usort($stats['files'], function($a, $b) {
            return $b['time'] - $a['time'];
        });

        return $stats;
    }

    /**
     * 格式化文件大小
     */
    public static function formatSize($bytes)
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return round($bytes / 1048576, 2) . ' MB';
        }
    }

    /**
     * 渲染错误提示
     */
    public static function renderError($message = '')
    {
        $html = '<div class="daily-60s-wrap">';
        $html .= '<div class="daily-60s-error">';
        if (!empty($message)) {
            $html .= '<p>' . htmlspecialchars($message) . '</p>';
        } else {
            $html .= '<p>暂未获取到今日新闻数据，请稍后刷新重试。</p>';
        }
        $html .= '<p style="margin-top:15px;"><button class="daily-60s-refresh-btn" onclick="window.location.reload()">重新加载</button></p>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= self::renderStyles();
        return $html;
    }

    /**
     * 渲染懒加载脚本
     */
    public static function renderLazyLoadScript()
    {
        return '<script>
(function() {
    var lazyImages = document.querySelectorAll("img.daily-60s-lazy[data-src]");
    if (lazyImages.length === 0) return;

    function loadImage(img) {
        var src = img.getAttribute("data-src");
        if (!src) return;
        var tempImg = new Image();
        tempImg.onload = function() {
            img.src = src;
            img.classList.add("daily-60s-loaded");
            img.removeAttribute("data-src");
        };
        tempImg.onerror = function() {
            img.classList.add("daily-60s-error");
            img.removeAttribute("data-src");
        };
        tempImg.src = src;
    }

    if ("IntersectionObserver" in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    loadImage(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: "100px 0px", threshold: 0 });

        lazyImages.forEach(function(img) {
            observer.observe(img);
        });
    } else {
        // 不支持 IntersectionObserver 的浏览器直接加载
        lazyImages.forEach(function(img) {
            loadImage(img);
        });
    }
})();
</script>';
    }

    /**
     * 渲染样式
     */
    public static function renderStyles()
    {
        $color1 = self::getConfig('headerColor1', '#667eea');
        $color2 = self::getConfig('headerColor2', '#764ba2');

        // 验证颜色格式
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color1)) {
            $color1 = '#667eea';
        }
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color2)) {
            $color2 = '#764ba2';
        }

        return '<style>
.daily-60s-wrap {
    max-width: 720px;
    margin: 20px auto;
    padding: 10px 0;
}
.daily-60s-header {
    text-align: center;
    margin-bottom: 25px;
    padding: 20px;
    background: linear-gradient(135deg, ' . htmlspecialchars($color1) . ' 0%, ' . htmlspecialchars($color2) . ' 100%);
    color: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
}
.daily-60s-header h2 {
    margin: 0 0 8px;
    font-size: 24px;
    font-weight: bold;
}
.daily-60s-header .subtitle {
    font-size: 14px;
    opacity: 0.9;
    margin-bottom: 5px;
}
.daily-60s-header .date {
    font-size: 15px;
    opacity: 0.95;
}
.daily-60s-news-list {
    background: #fff;
    border-radius: 12px;
    padding: 25px 30px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    margin-bottom: 20px;
}
.daily-60s-news-list ol {
    padding-left: 0;
    margin: 0;
    list-style: none;
    counter-reset: news-counter;
}
.daily-60s-news-list li {
    position: relative;
    padding: 12px 0 12px 45px;
    border-bottom: 1px dashed #eee;
    line-height: 1.7;
    font-size: 15px;
    color: #333;
    counter-increment: news-counter;
}
.daily-60s-news-list li:last-child {
    border-bottom: none;
}
.daily-60s-news-list li::before {
    content: counter(news-counter);
    position: absolute;
    left: 0;
    top: 12px;
    width: 30px;
    height: 30px;
    background: linear-gradient(135deg, ' . htmlspecialchars($color1) . ' 0%, ' . htmlspecialchars($color2) . ' 100%);
    color: #fff;
    border-radius: 50%;
    text-align: center;
    line-height: 30px;
    font-size: 14px;
    font-weight: bold;
}
.daily-60s-weiyu {
    background: #fff8e1;
    border-left: 4px solid #ffc107;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 15px;
    color: #5d4037;
}
.daily-60s-weiyu strong {
    color: #e65100;
}
.daily-60s-image {
    text-align: center;
    margin: 25px 0;
}
.daily-60s-image img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: opacity 0.4s ease;
}
.daily-60s-image img.daily-60s-lazy {
    opacity: 0.3;
    background: #f5f5f5;
}
.daily-60s-image img.daily-60s-loaded {
    opacity: 1;
}
.daily-60s-image img.daily-60s-error {
    opacity: 0.3;
    filter: grayscale(100%);
}
.daily-60s-tip {
    text-align: center;
    color: #999;
    font-size: 13px;
    margin-top: 20px;
    padding: 10px;
}
.daily-60s-refresh-btn {
    display: inline-block;
    padding: 8px 24px;
    background: linear-gradient(135deg, ' . htmlspecialchars($color1) . ' 0%, ' . htmlspecialchars($color2) . ' 100%);
    color: #fff;
    border: none;
    border-radius: 25px;
    cursor: pointer;
    font-size: 14px;
    margin: 10px auto;
    transition: opacity 0.3s;
}
.daily-60s-refresh-btn:hover {
    opacity: 0.9;
}
.daily-60s-error {
    text-align: center;
    padding: 40px 20px;
    color: #999;
}

/* 夜间模式 - 系统偏好 */
@media (prefers-color-scheme: dark) {
    .daily-60s-news-list {
        background: #2d2d2d;
        box-shadow: 0 2px 12px rgba(0,0,0,0.3);
    }
    .daily-60s-news-list li {
        color: #ddd;
        border-bottom-color: #404040;
    }
    .daily-60s-weiyu {
        background: #3e2723;
        color: #d7ccc8;
    }
}

/* 夜间模式 - class 类名 */
html.dark .daily-60s-news-list,
body.dark .daily-60s-news-list,
html.night .daily-60s-news-list,
body.night .daily-60s-news-list,
html.dark-mode .daily-60s-news-list,
body.dark-mode .daily-60s-news-list {
    background: #2d2d2d;
    box-shadow: 0 2px 12px rgba(0,0,0,0.3);
}
html.dark .daily-60s-news-list li,
body.dark .daily-60s-news-list li,
html.night .daily-60s-news-list li,
body.night .daily-60s-news-list li,
html.dark-mode .daily-60s-news-list li,
body.dark-mode .daily-60s-news-list li {
    color: #ddd;
    border-bottom-color: #404040;
}
html.dark .daily-60s-weiyu,
body.dark .daily-60s-weiyu,
html.night .daily-60s-weiyu,
body.night .daily-60s-weiyu,
html.dark-mode .daily-60s-weiyu,
body.dark-mode .daily-60s-weiyu {
    background: #3e2723;
    color: #d7ccc8;
}
</style>';
    }
}

/**
 * 自定义表单 HTML 块元素
 */
class HtmlBlock extends \Typecho\Widget\Helper\Layout
{
    private $htmlContent;

    public function __construct($htmlContent)
    {
        parent::__construct('div');
        $this->htmlContent = $htmlContent;
    }

    public function render()
    {
        echo $this->htmlContent;
    }
}
