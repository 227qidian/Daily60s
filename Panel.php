<?php
/**
 * 每日60s新闻插件 - 缓存管理面板
 *
 * 提供缓存统计、文件列表、手动刷新和清理功能。
 *
 * @package Daily60s
 */

namespace TypechoPlugin\Daily60s;

use Typecho\Widget;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 缓存管理面板类
 */
class Panel
{
    /**
     * 面板入口
     */
    public static function render()
    {
        $stats = Plugin::getCacheStats();
        $security = Widget::widget('Widget\Security');
        $actionUrl = $security->getTokenUrl('/action/daily60s');
        $actionJoiner = strpos($actionUrl, '?') === false ? '?' : '&';

        // 处理操作结果消息
        $message = '';
        $messageType = '';
        if (isset($_GET['result'])) {
            switch ($_GET['result']) {
                case 'refresh_success':
                    $message = '缓存刷新成功，已重新获取当天新闻数据。';
                    $messageType = 'success';
                    break;
                case 'refresh_fail':
                    $message = '缓存已清空，但重新获取数据失败，请检查 API 设置。';
                    $messageType = 'warning';
                    break;
                case 'clear_success':
                    $message = '所有缓存已清空。';
                    $messageType = 'success';
                    break;
                case 'delete_success':
                    $message = '文件已删除。';
                    $messageType = 'success';
                    break;
                case 'delete_fail':
                    $message = '文件删除失败，请检查权限。';
                    $messageType = 'error';
                    break;
            }
        }

        // 重新获取统计信息（操作后更新）
        if (!empty($message)) {
            $stats = Plugin::getCacheStats();
        }
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>每日60s缓存管理 - <?php echo Widget::widget('Widget\Options')->title; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f0f2f5;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 25px 30px;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header h1 {
            font-size: 22px;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 14px;
            opacity: 0.9;
        }
        .content {
            background: #fff;
            padding: 30px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        .stat-card .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6c757d;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
        }
        .btn-group {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-block;
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 500;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }
        .btn-primary:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .btn-danger {
            background: #e74c3c;
            color: #fff;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        .btn-secondary {
            background: #95a5a6;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        .file-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .file-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-size: 13px;
            color: #6c757d;
            border-bottom: 2px solid #dee2e6;
        }
        .file-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        .file-table tr:hover {
            background: #f8f9fa;
        }
        .file-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .file-type-json {
            background: #e3f2fd;
            color: #1976d2;
        }
        .file-type-image {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        .delete-link {
            color: #e74c3c;
            text-decoration: none;
            font-size: 13px;
        }
        .delete-link:hover {
            text-decoration: underline;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .info-box {
            background: #e7f5ff;
            border-left: 4px solid #2196f3;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #333;
        }
        .info-box code {
            background: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 13px;
        }
        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .btn-group {
                flex-direction: column;
            }
            .btn {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>每日60s缓存管理</h1>
            <p>管理每日60s新闻插件的缓存文件</p>
        </div>

        <div class="content">
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <div class="info-box">
                <strong>缓存目录：</strong><code><?php echo Plugin::CACHE_DIR; ?></code><br>
                <strong>说明：</strong>所有缓存文件（JSON 数据和图片）统一存放在此目录。点击「刷新缓存」会清空所有缓存并立即重新获取当天新闻数据。
            </div>

            <!-- 统计信息 -->
            <div class="section-title">缓存统计</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['fileCount']; ?></div>
                    <div class="stat-label">文件总数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['jsonCount']; ?></div>
                    <div class="stat-label">JSON 数据</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['imageCount']; ?></div>
                    <div class="stat-label">图片文件</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['totalSizeText']; ?></div>
                    <div class="stat-label">总大小</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" style="font-size:16px;"><?php echo $stats['lastUpdate']; ?></div>
                    <div class="stat-label">最近更新</div>
                </div>
            </div>

            <!-- 操作按钮 -->
            <div class="section-title">缓存操作</div>
            <div class="btn-group">
                <a href="<?php echo $actionUrl . $actionJoiner; ?>do=refresh&_redirect=1" class="btn btn-primary"
                   onclick="return confirm('确定要刷新缓存吗？\n\n此操作将：\n1. 清空所有缓存文件\n2. 立即重新获取当天新闻数据\n\n是否继续？');">
                    刷新缓存（清空并重新获取）
                </a>
                <a href="<?php echo $actionUrl . $actionJoiner; ?>do=clear&_redirect=1" class="btn btn-danger"
                   onclick="return confirm('确定要清空所有缓存吗？\n\n此操作仅清空缓存文件，不会重新获取数据。\n\n是否继续？');">
                    清空所有缓存
                </a>
                <a href="javascript:location.reload();" class="btn btn-secondary">刷新页面</a>
            </div>

            <!-- 文件列表 -->
            <div class="section-title">缓存文件列表</div>
            <?php if (empty($stats['files'])): ?>
                <div class="empty-state">
                    <p>暂无缓存文件</p>
                    <p style="margin-top:10px; font-size:13px;">访问包含 [daily60s] 短代码的文章后将自动生成缓存</p>
                </div>
            <?php else: ?>
                <table class="file-table">
                    <thead>
                        <tr>
                            <th>文件名</th>
                            <th>类型</th>
                            <th>大小</th>
                            <th>更新时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['files'] as $file): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($file['name']); ?></td>
                            <td>
                                <?php
                                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                                if ($ext === 'json') {
                                    echo '<span class="file-type file-type-json">JSON</span>';
                                } else {
                                    echo '<span class="file-type file-type-image">图片</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo $file['sizeText']; ?></td>
                            <td><?php echo $file['timeText']; ?></td>
                            <td>
                                <a href="<?php echo $actionUrl . $actionJoiner; ?>do=delete&file=<?php echo urlencode($file['name']); ?>&_redirect=1"
                                   class="delete-link"
                                   onclick="return confirm('确定要删除文件 <?php echo htmlspecialchars($file['name']); ?> 吗？');">
                                    删除
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
        <?php
    }
}

// 渲染面板
Panel::render();
