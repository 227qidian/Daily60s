<?php
/**
 * 每日60s新闻插件 - Action 处理
 *
 * 处理手动刷新缓存、定时自动预热等接口请求。
 *
 * @package Daily60s
 */

namespace TypechoPlugin\Daily60s;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends \Widget\Base\Options implements \Widget\ActionInterface
{
    /**
     * Action 入口
     */
    public function action()
    {
        $do = $this->request->get('do', '');

        // preheat 供宝塔计划任务调用，不带 token，跳过 CSRF 校验
        // 其他操作必须通过 token 校验
        if ($do !== 'preheat') {
            $this->security->protect();
        }

        switch ($do) {
            case 'preheat':
                $this->preheat();
                break;
            case 'refresh':
                $this->refresh();
                break;
            case 'clear':
                $this->clear();
                break;
            case 'delete':
                $this->deleteFile();
                break;
            case 'stats':
                $this->stats();
                break;
            default:
                $this->response->throwJson(array(
                    'code' => 400,
                    'message' => '未知操作'
                ));
        }
    }

    /**
     * 检查是否需要重定向回管理面板
     */
    private function checkRedirect(string $result): void
    {
        $redirect = $this->request->get('_redirect', '');
        if ($redirect === '1') {
            $panelUrl = \Utils\Helper::url('Daily60s/Panel.php');
            $joiner = strpos($panelUrl, '?') === false ? '?' : '&';
            $this->response->redirect($panelUrl . $joiner . 'result=' . $result);
        }
    }

    /**
     * 定时预热接口（供宝塔计划任务调用）
     * 访问地址：/action/daily60s?do=preheat
     */
    private function preheat(): void
    {
        // 检查是否开启定时预热
        $autoPreheat = Plugin::getConfig('autoPreheat', '0');

        if ($autoPreheat != '1') {
            // 未开启定时预热，返回提示
            $this->response->throwJson(array(
                'code' => 403,
                'message' => '定时预热功能未开启，请在插件设置中开启「定时自动预热」选项。'
            ));
        }

        $apiType = Plugin::getConfig('apiType', 'json');
        $today = date('Y-m-d');
        $cachePath = Plugin::getCachePath();

        // 确保缓存目录存在
        Plugin::initCacheDir();

        if ($apiType === 'image') {
            // 图片模式预热
            $cacheFile = $cachePath . $today . '.jpg';

            if (is_file($cacheFile) && filesize($cacheFile) > 0) {
                $this->response->throwJson(array(
                    'code' => 200,
                    'message' => '今日图片缓存已存在，无需重复预热',
                    'date' => $today,
                    'cacheFile' => $today . '.jpg'
                ));
            }

            $imageData = Plugin::fetchImageWithLock();

            if ($imageData !== false) {
                $writeResult = @file_put_contents($cacheFile, $imageData);
                if ($writeResult !== false && $writeResult > 0) {
                    $this->response->throwJson(array(
                        'code' => 200,
                        'message' => '图片预热成功',
                        'date' => $today,
                        'cacheFile' => $today . '.jpg',
                        'size' => strlen($imageData)
                    ));
                }

                $this->response->throwJson(array(
                    'code' => 500,
                    'message' => '图片预热失败，缓存写入失败，请检查目录权限',
                    'date' => $today
                ));
            } else {
                $this->response->throwJson(array(
                    'code' => 500,
                    'message' => '图片预热失败，所有 API 均无法访问',
                    'date' => $today
                ));
            }
        } else {
            // JSON 或文本模式预热
            $cacheFile = $cachePath . $today . '.json';

            if (is_file($cacheFile)) {
                $cacheContent = @file_get_contents($cacheFile);
                $cacheData = json_decode($cacheContent, true);
                if ($cacheData && isset($cacheData['data'])) {
                    $this->response->throwJson(array(
                        'code' => 200,
                        'message' => '今日缓存已存在，无需重复预热',
                        'date' => $today,
                        'cacheFile' => $today . '.json'
                    ));
                }
            }

            $newsData = Plugin::getNewsData();

            if ($newsData && isset($newsData['data'])) {
                $imageCached = false;
                if ($apiType === 'json' && !empty($newsData['data']['image'])) {
                    $localizeImage = Plugin::getConfig('localizeImage', '0');
                    if ($localizeImage == '1') {
                        $localUrl = Plugin::localizeImage($newsData['data']['image'], $today);
                        $imageCached = $localUrl ? true : false;
                    } else {
                        $cachedImageUrl = Plugin::cacheJsonImage($newsData['data']['image'], $today);
                        $imageCached = $cachedImageUrl ? true : false;
                    }
                }

                $this->response->throwJson(array(
                    'code' => 200,
                    'message' => $apiType === 'text' ? '文本模式预热成功' : 'JSON 预热成功',
                    'date' => $today,
                    'cacheFile' => $today . '.json',
                    'imageCached' => $imageCached,
                    'newsCount' => isset($newsData['data']['news']) ? count($newsData['data']['news']) : 0
                ));
            } else {
                $this->response->throwJson(array(
                    'code' => 500,
                    'message' => '预热失败，所有 API 均无法访问',
                    'date' => $today
                ));
            }
        }
    }

    /**
     * 手动刷新缓存：清空所有缓存并重新获取当天数据
     */
    private function refresh(): void
    {
        // 清空所有缓存文件
        $deletedCount = Plugin::refreshCache();

        // 重新获取当天数据
        $apiType = Plugin::getConfig('apiType', 'json');
        $today = date('Y-m-d');
        $fetchSuccess = false;
        $fetchMessage = '';

        if ($apiType === 'image') {
            $imageData = Plugin::fetchImageWithLock();
            if ($imageData !== false) {
                $cachePath = Plugin::getCachePath();
                $cacheFile = $cachePath . $today . '.jpg';
                $writeResult = @file_put_contents($cacheFile, $imageData);
                if ($writeResult !== false && $writeResult > 0) {
                    $fetchSuccess = true;
                    $fetchMessage = '图片缓存已重新获取';
                } else {
                    $fetchMessage = '图片获取成功，但缓存写入失败，请检查目录权限';
                }
            } else {
                $fetchMessage = '图片获取失败，请检查 API 设置';
            }
        } else {
            $newsData = Plugin::getNewsData();
            if ($newsData && isset($newsData['data'])) {
                if ($apiType === 'json' && !empty($newsData['data']['image'])) {
                    $localizeImage = Plugin::getConfig('localizeImage', '0');
                    if ($localizeImage == '1') {
                        Plugin::localizeImage($newsData['data']['image'], $today);
                    } else {
                        Plugin::cacheJsonImage($newsData['data']['image'], $today);
                    }
                }
                $fetchSuccess = true;
                $fetchMessage = $apiType === 'text' ? '文本数据已重新获取' : 'JSON 数据已重新获取';
            } else {
                $fetchMessage = '数据获取失败，请检查 API 设置';
            }
        }

        // 检查是否需要重定向
        $redirect = $this->request->get('_redirect', '');
        if ($redirect === '1') {
            $this->checkRedirect($fetchSuccess ? 'refresh_success' : 'refresh_fail');
        }

        $this->response->throwJson(array(
            'code' => $fetchSuccess ? 200 : 500,
            'message' => '已清理 ' . $deletedCount . ' 个缓存文件。' . $fetchMessage,
            'deletedCount' => $deletedCount,
            'fetchSuccess' => $fetchSuccess,
            'date' => $today
        ));
    }

    /**
     * 仅清空所有缓存（不重新获取）
     */
    private function clear(): void
    {
        $deletedCount = Plugin::refreshCache();

        // 检查是否需要重定向
        $this->checkRedirect('clear_success');

        $this->response->throwJson(array(
            'code' => 200,
            'message' => '已清理 ' . $deletedCount . ' 个缓存文件',
            'deletedCount' => $deletedCount
        ));
    }

    /**
     * 删除单个缓存文件
     */
    private function deleteFile(): void
    {
        $fileName = $this->request->get('file', '');

        // 安全检查：只允许删除缓存目录中的文件
        if (empty($fileName) || strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false || strpos($fileName, '..') !== false) {
            $this->checkRedirect('delete_fail');
            $this->response->throwJson(array(
                'code' => 400,
                'message' => '无效的文件名'
            ));
        }

        $cachePath = Plugin::getCachePath();
        $filePath = $cachePath . $fileName;

        // 检查文件是否在缓存目录内
        $realPath = realpath($filePath);
        $realCachePath = realpath($cachePath);

        if ($realPath === false || $realCachePath === false || strpos($realPath, $realCachePath) !== 0) {
            $this->checkRedirect('delete_fail');
            $this->response->throwJson(array(
                'code' => 403,
                'message' => '无权访问此文件'
            ));
        }

        // 不允许删除 index.html 和 .lock
        if (in_array($fileName, array('index.html', '.lock'))) {
            $this->checkRedirect('delete_fail');
            $this->response->throwJson(array(
                'code' => 403,
                'message' => '不允许删除此文件'
            ));
        }

        if (is_file($filePath)) {
            if (@unlink($filePath)) {
                $this->checkRedirect('delete_success');
                $this->response->throwJson(array(
                    'code' => 200,
                    'message' => '文件 ' . $fileName . ' 已删除'
                ));
            } else {
                $this->checkRedirect('delete_fail');
                $this->response->throwJson(array(
                    'code' => 500,
                    'message' => '文件删除失败，请检查权限'
                ));
            }
        } else {
            $this->checkRedirect('delete_fail');
            $this->response->throwJson(array(
                'code' => 404,
                'message' => '文件不存在'
            ));
        }
    }

    /**
     * 获取缓存统计信息
     */
    private function stats(): void
    {
        $stats = Plugin::getCacheStats();

        $this->response->throwJson(array(
            'code' => 200,
            'data' => $stats
        ));
    }
}
