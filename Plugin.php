<?php

declare(strict_types=1);

namespace TypechoPlugin\SoMuch;

use Typecho\Plugin\Exception;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Archive;
use Widget\Metas\Category\Rows as CategoryRows;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 搜索增强插件 for Typecho
 *
 * @package SoMuch
 * @author Vex
 * @version 0.1.3
 * @since 1.2.0
 * @link https://github.com/vndroid/somuch
 */
class Plugin implements PluginInterface
{
    /**
     * 频率限制计数在 Session 中的键前缀
     */
    private const SESSION_PREFIX = 'somuch_rate_';

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate(): string
    {
        \Typecho\Plugin::factory('Widget\Archive')->search = [Plugin::class, 'justSoSo'];

        return _t('搜索增强功能已激活，可以对插件进行设置！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     */

    public static function deactivate(): void
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
        $soMode = new Radio('soMode', ['1' => _t('常规模式'), '2' => _t('仅标题模式')], '1', _t('搜索模式'), '');
        $form->addInput($soMode
            ->addRule('required', _t('请选择搜索模式'))
            ->addRule(SearchOptions::isValidSearchMode(...), _t('搜索模式无效')));

        $midFilter = new Text(
            'midFilter',
            null,
            null,
            _t('分类黑名单'),
            _t('搜索结果会过滤指定的分类，请填写分类的 mid 值，多个用英文逗号分隔。只接受分类 mid，标签 mid 无效。')
            . self::describeCategories()
        );
        $form->addInput($midFilter->addRule(
            self::validateMidFilter(...),
            _t('分类黑名单只能填写已存在的分类 mid（纯数字，英文逗号分隔），不能填写标签或不存在的 mid')
        ));

        $midInherit = new Radio(
            'midInherit',
            ['1' => _t('包含子分类'), '0' => _t('仅精确匹配')],
            '1',
            _t('黑名单范围'),
            _t('「包含子分类」会连同所填分类下的所有子孙分类一起屏蔽；「仅精确匹配」只屏蔽所填的分类本身')
        );
        $form->addInput($midInherit
            ->addRule('required', _t('请选择黑名单范围'))
            ->addRule(SearchOptions::isValidToggle(...), _t('黑名单范围无效')));

        $pageSize = new Text(
            'pageSize',
            null,
            null,
            _t('结果分页'),
            _t('搜索结果每页的文章数量，必须是 2 到 100 之间的偶数；留空则沿用系统设置，系统值为奇数时会自动向上取整为偶数')
        );
        $pageSize->input
            ->setAttribute('type', 'number')
            ->setAttribute('min', SearchOptions::MIN_PAGE_SIZE)
            ->setAttribute('max', SearchOptions::MAX_PAGE_SIZE)
            ->setAttribute('step', 2);
        $form->addInput($pageSize->addRule(
            SearchOptions::isValidPageSize(...),
            _t('分页数量必须是 2 到 100 之间的偶数，或留空使用系统默认值')
        ));

        $extendLimit = new Checkbox('extendLimit', ['rate' => _t('频率限制，开启后下方设置会生效')], [], _t('拓展设置'), '');
        $form->addInput($extendLimit->multiMode());

        $isAdmin = new Radio('isAdmin', ['0' => _t('关闭'), '1' => _t('开启')], '0', _t('约束管理员'), _t('开启后管理员也会受到搜索频率限制，关闭则不限制管理员'));
        $form->addInput($isAdmin
            ->addRule('required', _t('请选择是否约束管理员'))
            ->addRule(SearchOptions::isValidToggle(...), _t('约束管理员选项无效')));

        $count = new Text(
            'count',
            null,
            '1',
            _t('搜索限制频率（次）'),
            _t('启用频率限制后生效，必须是 1 到 1000 之间的整数')
        );
        $count->input
            ->setAttribute('type', 'number')
            ->setAttribute('min', SearchOptions::MIN_RATE_COUNT)
            ->setAttribute('max', SearchOptions::MAX_RATE_COUNT)
            ->setAttribute('step', 1);
        $form->addInput($count
            ->addRule('required', _t('请填写搜索次数'))
            ->addRule(
                SearchOptions::isValidRateCount(...),
                _t('搜索次数必须是 1 到 1000 之间的整数')
            ));

        $time = new Text(
            'time',
            null,
            '60',
            _t('搜索限制时间（秒）'),
            _t('启用频率限制后生效，必须是 1 到 86400 之间的整数秒数')
        );
        $time->input
            ->setAttribute('type', 'number')
            ->setAttribute('min', SearchOptions::MIN_RATE_SECONDS)
            ->setAttribute('max', SearchOptions::MAX_RATE_SECONDS)
            ->setAttribute('step', 1);
        $form->addInput($time
            ->addRule('required', _t('请填写限制时间'))
            ->addRule(
                SearchOptions::isValidRateSeconds(...),
                _t('限制时间必须是 1 到 86400 之间的整数秒数')
            ));

        $content = new Text(
            'content',
            null,
            null,
            _t('被限制后的显示提示'),
            _t('留空时会根据限制时间和次数自动生成，最多 200 个字符')
        );
        $content->input->setAttribute('maxlength', SearchOptions::MAX_RATE_MESSAGE_LENGTH);
        $form->addInput($content->addRule(
            SearchOptions::isValidRateMessage(...),
            _t('提示内容不能超过 200 个字符')
        ));
    }

    /**
     * 个人用户的配置面板
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form): void
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
    public static function justSoSo(string $keywords, Archive $obj): void
    {
        $searchOptions = SearchOptions::fromConfig(Helper::options()->plugin('SoMuch'));

        if ($searchOptions->rateLimitEnabled) {
            // 判断是否为管理员，若关闭约束管理员选项则跳过管理员的频率限制
            $user = \Widget\User::alloc();
            if ($searchOptions->limitAdministrators || !$user->hasLogin() || !$user->pass('administrator', true)) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }

                // 与核心分页导航使用同一个页码来源；?page=abc / ?page=0 会得到 0，统一收敛到 1
                $page = max(1, intval($obj->getCurrentPage()));

                // Session 本身就是一个客户端一份，两个访客不会共用，所以键名用固定前缀即可。
                // 早期版本把访客 IP 拼进键名，而该 IP 在反向代理后取自 X-Forwarded-For：
                // 请求方每次换一个伪造的 IP 头就换一组计数键，限流形同虚设，
                // 同时还能往同一个 Session 里无限追加键、覆盖其他组件以 _count / _time 结尾的值。
                $countKey = self::SESSION_PREFIX . 'count';
                $timeKey = self::SESSION_PREFIX . 'time';
                $keywordsKey = self::SESSION_PREFIX . 'keywords';

                // 只有「对上一次已放行的同一关键词翻页」才免检；
                // 直接请求 /search/新词/2/ 或 ?page=2 仍然计数，否则换个页码就能绕过限流
                $isPaging = $page > 1 && ($_SESSION[$keywordsKey] ?? null) === $keywords;

                if (!$isPaging) {
                    $lastSearchTime = $_SESSION[$timeKey] ?? 0;
                    $searchCount = $_SESSION[$countKey] ?? 0;
                    $timeDiff = time() - $lastSearchTime;

                    if ($timeDiff < $searchOptions->rateLimitSeconds) {
                        // 在时间窗口内
                        if ($searchCount >= $searchOptions->rateLimitCount) {
                            self::rejectSearch(
                                $searchOptions->rateLimitMessage,
                                $searchOptions->rateLimitSeconds - $timeDiff
                            );
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
     * 输出拒绝页面并终止当前请求
     *
     * @param string $content 提示文案
     * @param int $retryAfter 距离本轮时间窗口结束还有多少秒
     */
    private static function rejectSearch(string $content, int $retryAfter): never
    {
        // 这是一次限流，不是一份搜索结果：429 + Retry-After 告诉客户端该等多久再来，
        // no-store 防止 CDN、反向代理或浏览器把这张提示页当成该关键词的搜索结果缓存下来，
        // 连累后面的正常访客。
        //
        // 状态码这里要绕两个坑：
        // 1. Common::init() 注册了一个输出缓冲回调，脚本结束冲刷缓冲区时会拿 Response 里的
        //    状态码重新发一遍状态行，把这里设的 429 改回 200；而改用 Response::setStatus(429)
        //    又会撞上核心 HTTP_CODE 表里没有 429（1.2 / 1.3 都没有），每次限流都留下一条
        //    Undefined array key 警告。所以先把缓冲区连同那个回调一起关掉
        //    （以输出缓冲抓取整页的缓存类插件同样拿不到这张页面，正合适）。
        // 2. 状态行必须显式写出来：前面已经发过一次 "HTTP/1.1 200 OK" 之后，
        //    光调 http_response_code() 在部分 SAPI 上不会改写已经定下的状态行。
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('HTTP/1.1 429 Too Many Requests', true, 429);
            header('Retry-After: ' . max(1, $retryAfter));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Content-Type: text/html; charset=UTF-8');
        }

        $pluginUrl = (string) Helper::options()->pluginUrl;
        $rootUrl = (string) Helper::options()->rootUrl;
        include __DIR__ . '/theme.tpl';
        exit;
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
            if (!self::categoryExists($categories, $mid)) {
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
            if (!self::categoryExists($categories, $mid)) {
                continue;
            }

            $blocked[] = $mid;

            if ($withChildren) {
                foreach (self::getAllChildCategoryIds($categories, $mid) as $childId) {
                    $blocked[] = intval($childId);
                }
            }
        }

        return array_values(array_unique($blocked));
    }

    /**
     * Typecho 1.3 将 getCategory() 重命名为 getRow()。
     */
    private static function categoryExists(CategoryRows $categories, int $mid): bool
    {
        return method_exists($categories, 'getRow')
            ? null !== $categories->getRow($mid)
            : null !== $categories->getCategory($mid);
    }

    /**
     * Typecho 1.3 将 getAllChildren() 重命名为 getAllChildIds()。
     *
     * @return int[]
     */
    private static function getAllChildCategoryIds(CategoryRows $categories, int $mid): array
    {
        $ids = method_exists($categories, 'getAllChildIds')
            ? $categories->getAllChildIds($mid)
            : $categories->getAllChildren($mid);

        return array_map('intval', $ids);
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
}
