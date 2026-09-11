<?php

namespace TypechoPlugin\SoMuch;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Metas\Category\Rows as CategoryRows;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 搜索增强插件 for Typecho
 *
 * @package SoMuch
 * @author Vex
 * @version 0.1.2
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

        $midFilter = new Text(
            'midFilter',
            null,
            null,
            _t('分类黑名单'),
            _t('搜索结果会过滤指定的分类，请填写分类的 mid 值，多个用英文逗号分隔。只接受分类 mid，标签 mid 无效。')
            . self::describeCategories()
        );
        $form->addInput($midFilter->addRule(
            [__CLASS__, 'validateMidFilter'],
            _t('分类黑名单只能填写已存在的分类 mid（纯数字，英文逗号分隔），不能填写标签或不存在的 mid')
        ));

        $midInherit = new Radio(
            'midInherit',
            ['1' => _t('包含子分类'), '0' => _t('仅精确匹配')],
            '1',
            _t('黑名单范围'),
            _t('「包含子分类」会连同所填分类下的所有子孙分类一起屏蔽；「仅精确匹配」只屏蔽所填的分类本身')
        );
        $form->addInput($midInherit);

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

        $isAdmin = new Radio('isAdmin', array('0' => _t('关闭'), '1' => _t('开启')), '0', _t('约束管理员'), _t('开启后管理员也会受到搜索频率限制，关闭则不限制管理员'));
        $form->addInput($isAdmin);

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
        // 插件配置（注意与站点配置 Helper::options() 区分）
        $pluginOptions = Helper::options()->plugin('SoMuch');
        $count = intval($pluginOptions->count) ?: 1;
        $time = intval($pluginOptions->time) ?: 60;
        $content = $pluginOptions->content ?: "{$time}秒内只能搜索{$count}次，请稍后再试！";
        $soMode = intval($pluginOptions->soMode) === 2 ? 2 : 1;
        $keywords = (string) $keywords;

        if (!empty($pluginOptions->extendLimit) && in_array('rate', $pluginOptions->extendLimit)) {
            // 判断是否为管理员，若关闭约束管理员选项则跳过管理员的频率限制
            $isAdmin = intval($pluginOptions->isAdmin ?? 0);
            $user = \Widget\User::alloc();
            if ($isAdmin || !$user->hasLogin() || !$user->pass('administrator', true)) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                $ip = self::getGuestAddress();
                // 与核心分页导航使用同一个页码来源；?page=abc / ?page=0 会得到 0，统一收敛到 1
                $page = max(1, intval($obj->getCurrentPage()));

                if (empty($ip)) {
                    $content = '获取地址失败，请关闭 VPN 等相关工具后再尝试搜索！';
                    $pluginUrl = Helper::options()->pluginUrl;
                    $rootUrl = Helper::options()->rootUrl;
                    include __DIR__ . '/theme.tpl';
                    exit;
                }

                // 使用 IP 相关的 Session 键，避免多用户场景下的污染
                $countKey = $ip . '_count';
                $timeKey = $ip . '_time';
                $keywordsKey = $ip . '_keywords';

                // 只有「对上一次已放行的同一关键词翻页」才免检；
                // 直接请求 /search/新词/2/ 或 ?page=2 仍然计数，否则换个页码就能绕过限流
                $isPaging = $page > 1 && ($_SESSION[$keywordsKey] ?? null) === $keywords;

                if (!$isPaging) {
                    $lastSearchTime = $_SESSION[$timeKey] ?? 0;
                    $searchCount = $_SESSION[$countKey] ?? 0;
                    $timeDiff = time() - $lastSearchTime;

                    if ($timeDiff < $time) {
                        // 在时间窗口内
                        if ($searchCount >= $count) {
                            // 超过限制，显示限制提示
                            $pluginUrl = Helper::options()->pluginUrl;
                            $rootUrl = Helper::options()->rootUrl;
                            include __DIR__ . '/theme.tpl';
                            exit;
                        }
                        // 允许搜索，计数 +1
                        $_SESSION[$countKey] = $searchCount + 1;
                    } else {
                        // 超过时间窗口，重置计数和时间
                        $_SESSION[$timeKey] = time();
                        $_SESSION[$countKey] = 1;
                    }

                    $_SESSION[$keywordsKey] = $keywords;
                }
            }

        }
        include __DIR__ . '/search.php';
    }

    /**
     * 配置校验：分类黑名单只能是已存在的分类 mid
     *
     * @param string|null $value
     * @return bool
     */
    public static function validateMidFilter(?string $value): bool
    {
        [$mids, $invalid] = self::parseMidList($value);

        if ($invalid) {
            return false;
        }

        $categories = CategoryRows::alloc();
        foreach ($mids as $mid) {
            if (null === $categories->getRow($mid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 解析黑名单配置，得到要屏蔽的分类 mid（已按配置展开子孙分类）
     *
     * 只保留确实存在的分类：标签 mid、已删除的分类会被忽略，
     * 因此后续查询无需再关联 metas 表判断类型。
     *
     * @param string|null $value 配置原文
     * @param bool $withChildren 是否包含子孙分类
     * @return int[]
     */
    public static function resolveBlockedCategories(?string $value, bool $withChildren): array
    {
        [$mids] = self::parseMidList($value);

        if (!$mids) {
            return [];
        }

        $categories = CategoryRows::alloc();
        $blocked = [];

        foreach ($mids as $mid) {
            if (null === $categories->getRow($mid)) {
                continue;
            }

            $blocked[] = $mid;

            if ($withChildren) {
                foreach ($categories->getAllChildIds($mid) as $childId) {
                    $blocked[] = intval($childId);
                }
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * 把「1, 2,3」拆成正整数列表，同时返回无法识别的片段
     *
     * @param string|null $value
     * @return array{0: int[], 1: string[]}
     */
    private static function parseMidList(?string $value): array
    {
        $mids = [];
        $invalid = [];

        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);

            if ('' === $part) {
                continue;
            }

            if (ctype_digit($part) && intval($part) > 0) {
                $mids[] = intval($part);
            } else {
                $invalid[] = $part;
            }
        }

        return [array_values(array_unique($mids)), $invalid];
    }

    /**
     * 在配置说明里列出现有分类，方便对照 mid 填写
     *
     * @return string
     */
    private static function describeCategories(): string
    {
        try {
            $categories = CategoryRows::alloc();
        } catch (\Throwable $e) {
            return '';
        }

        $items = [];
        while ($categories->next()) {
            $items[] = htmlspecialchars(str_repeat('— ', intval($categories->levels)) . $categories->name)
                . ' <code>' . intval($categories->mid) . '</code>';
        }

        return $items ? '<br>' . _t('现有分类：') . implode('，', $items) : '';
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
