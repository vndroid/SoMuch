<?php

namespace TypechoPlugin\SoMuch;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 搜索增强插件 for Typecho
 *
 * @package SoMuch
 * @author Vex
 * @version 0.1.0
 * @link https://github.com/vndroid/somuch
 */
class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate(): string
    {
        \Typecho\Plugin::factory('Widget_Archive')->search = array(__CLASS__, 'justSoSo');

        return _t('搜索增强功能已激活，可以对插件进行设置！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     */

    public static function deactivate()
    {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Form $form 配置面板
     * @return void
     */

    public static function config(Form $form): void
    {
        $soMode = new Radio('soMode', array('1' => _t('常规模式'), '2' => _t('仅标题模式')), '1', _t('搜索模式'), _t(""));
        $form->addInput($soMode);

        $midFilter = new Text('midFilter', NULL, NULL, _t('文章分类黑明单'), _t('搜索结果会过滤指定的分类，请填写 mid 值，多个用英文逗号分隔'));
        $form->addInput($midFilter);

        $pageSize = new Text('pageSize', NULL, NULL, _t('结果分页'), _t('搜索结果每页的文章数量，留空则使用系统默认值'));
        $form->addInput($pageSize->addRule('isInteger', _t('请填纯数字'))->addRule(function ($value) {
            // 留空时跳过校验
            if ($value === '' || $value === null) {
                return true;
            }
            return intval($value) % 2 === 0;
        }, _t('为了显示美观，建议使用偶数分页数量')));

        $extendLimit = new Checkbox('extendLimit', array('rate' => _t('频率限制，开启后下方设置会生效'),), array(), _t('拓展设置'), _t(''));
        $form->addInput($extendLimit->multiMode());

        $count = new Text('count', NULL, '1', _t('搜索限制频率（次）'), _t(''));
        $form->addInput($count->addRule('isInteger', '请填纯数字次数'));

        $time = new Text('time', NULL, '60', _t('搜索限制时间（秒）'), _t(''));
        $form->addInput($time->addRule('isInteger', '请填正确秒数'));

        $content = new Text('content', NULL, '一分钟只能搜索一次，请稍后再试！', _t('被限制后的显示提示'), _t(''));
        $form->addInput($content);
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 插件实现方法
     *
     * @access public
     * @param $keywords
     * @param $obj
     * @return void
     * @throws Exception
     */
    public static function justSoSo($keywords, $obj): void
    {
        // 获取插件配置
        $options = Helper::options()->plugin('SoMuch');
        $count = intval($options->count) ?: 1;
        $time = intval($options->time) ?: 60;
        $content = $options->content ?: "{$time}秒内只能搜索{$count}次，请稍后再试！";
        $soMode = intval($options->soMode) ?? 1;
        $searchQuery = '%' . str_replace(' ', '%', $keywords) . '%';

        if (!empty($options->extendLimit) && in_array('rate', $options->extendLimit)) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            $ip = self::getGuestAddress();
            $page = intval($obj->request->get('page') ?? 1);

            if (empty($ip)) {
                $content = '获取地址失败，请关闭 VPN 等相关工具后再尝试搜索！';
                include __DIR__ . '/theme.php';
                exit;
            }

            // 使用 IP 相关的 Session 键，避免多用户场景下的污染
            $countKey = $ip . '_count';
            $timeKey = $ip . '_time';

            // 只在第一页检查频率限制（翻页时不限制）
            if ($page === 1) {
                $lastSearchTime = $_SESSION[$timeKey] ?? 0;
                $searchCount = $_SESSION[$countKey] ?? 0;
                $timeDiff = time() - $lastSearchTime;

                if ($timeDiff < $time) {
                    // 在时间窗口内
                    if ($searchCount >= $count) {
                        // 超过限制，显示限制提示
                        include __DIR__ . '/theme.php';
                        exit;
                    }
                    // 允许搜索，计数 +1
                    $_SESSION[$countKey] = $searchCount + 1;
                } else {
                    // 超过时间窗口，重置计数和时间
                    $_SESSION[$timeKey] = time();
                    $_SESSION[$countKey] = 1;
                }
            }

            include __DIR__ . '/search.php';
        } else {
            include __DIR__ . '/search.php';
        }
    }

    /**
     * 获取访客IP地址
     *
     * @access private
     */
    private static function getGuestAddress()
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!empty($remoteAddr)) {
            // 如果是本地 IP，说明使用了反向代理，优先从代理头获取真实 IP
            if (self::isLocalIp($remoteAddr)) {
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                    $ip = trim($ips[0]);
                    if (!empty($ip)) {
                        return $ip;
                    }
                }
                // 如果没有 HTTP_X_FORWARDED_FOR，再尝试 HTTP_CLIENT_IP
                if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                    return $_SERVER['HTTP_CLIENT_IP'];
                }
            }
            // 不是本地 IP，直接返回
            return $remoteAddr;
        }
        return $remoteAddr;
    }

    /**
     * 检查 IP 是否为本地/内网 IP
     *
     * @param string $ip IP 地址
     * @return bool 是否为本地 IP
     */
    private static function isLocalIp(string $ip): bool
    {
        // 使用 PHP 内置的 filter_var 函数检查是否为私有 IP
        // FILTER_FLAG_NO_PRIV_RANGE: 拒绝私有 IP 范围
        // FILTER_FLAG_NO_RES_RANGE: 拒绝保留 IP 范围
        // 如果返回 false，说明是私有 IP（本地 IP）
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
