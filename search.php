<?php

declare(strict_types=1);

use Typecho\Date;
use Typecho\Db;
use TypechoPlugin\SoMuch\Plugin;
use TypechoPlugin\SoMuch\SearchMode;
use TypechoPlugin\SoMuch\SearchOptions;
use Widget\Archive;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 以下变量由 Plugin::justSoSo() 通过 include 注入：
 *
 * @var string $keywords 核心 filterSearchQuery() 过滤后的关键词（只剩文字、数字、下划线，词间单个空格）
 * @var Archive $obj Archive Widget 实例
 * @var SearchOptions $searchOptions 已归一化且不可变的搜索配置
 */

$db = Db::get();

// 与核心 searchHandle() 一致：PostgreSQL 的 LIKE 区分大小写，改用 ILIKE
$likeOp = 'pgsql' === $db->getAdapter()->getDriver() ? 'ILIKE' : 'LIKE';

// 转义 LIKE 通配符。核心过滤后 % 已不可能出现，但 _ 会保留（如 my_var），
// 不转义的话 _ 会匹配任意单字符。选 ! 做转义符：它在各数据库的字符串字面量里都没有特殊含义，
// 不受 MySQL NO_BACKSLASH_ESCAPES 影响，SQLite 也没有默认转义符，所以必须显式写 ESCAPE。
// 注意 "ESCAPE?" 中间不能有空格：Typecho 的 filterColumn() 会把后面跟空格的非关键字单词
// 当成列名加引号（变成 `ESCAPE`），紧跟 ? 则不会。
$terms = array_values(array_filter(
    explode(' ', $keywords),
    static fn(string $term): bool => $term !== ''
));
$searchQuery = '%' . implode('%', array_map(static function (string $term): string {
    return strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']);
}, $terms)) . '%';

$searchWhere = $searchOptions->mode === SearchMode::TitleOnly
    ? ["table.contents.title {$likeOp} ? ESCAPE?", $searchQuery, '!'] // 仅标题
    : [
        "table.contents.title {$likeOp} ? ESCAPE? OR table.contents.text {$likeOp} ? ESCAPE?",
        $searchQuery, '!', $searchQuery, '!'
    ]; // 标题及内容

// 自建查询绕过了核心 execute() 里的「定时发布」过滤，这里补上：
// 与核心同样用 < 和同一时间源（Options::___time() 已标 @deprecated，内部就是 Date::time()）。
// 与核心的差异：已登录作者在核心里能搜到自己的私密文章，本插件只放行 publish。
$po = $obj->select('table.contents.*')
    ->where("table.contents.password IS NULL OR table.contents.password = ''")
    ->where('table.contents.status = ?', 'publish')
    ->where('table.contents.created < ?', Date::time())
    ->where(...$searchWhere)
    ->where('table.contents.type = ?', 'post');

// 关键词被过滤成空串时（如 /search/%25/），render() 里的 checkPermalink 随后会 301 跳走，
// 但本查询在那之前就执行了；不加这句会白跑一次 LIKE '%%' 全表匹配
if (!$terms) {
    $po->where('1 = 0');
}

// 分类黑名单：anti-join。mid 已在 resolveBlockedCategories() 里核对过都是现存分类，
// 所以不必再关联 metas 判断 type；命中任意一个被屏蔽分类的文章都会被 IS NULL 排除，
// 未命中的只产生一行 NULL，不会重复，不需要 GROUP BY / DISTINCT。
$blockedMids = Plugin::resolveBlockedCategories(
    $searchOptions->categoryFilter,
    $searchOptions->includeChildCategories
);

if ($blockedMids) {
    $po->join(
        'table.relationships AS blocked_relationships',
        'blocked_relationships.cid = table.contents.cid'
        . ' AND blocked_relationships.mid IN (' . implode(',', array_map('intval', $blockedMids)) . ')',
        Db::LEFT_JOIN
    )->where('blocked_relationships.cid IS NULL');
}

$se = clone $po;
$obj->setCountSql($se);

// 优先使用插件配置的 pageSize（SearchOptions 已把它收敛成 2-100 的偶数）；
// 留空时沿用系统设置，只做「至少 2 条」和「向上取整为偶数」两步归一 ——
// MAX_PAGE_SIZE 是插件自己那个输入框的上限，不该拿来削站点自己的每页条数
$pageSize = $searchOptions->pageSize;

if (null === $pageSize) {
    $pageSize = max(SearchOptions::MIN_PAGE_SIZE, intval($obj->parameter->pageSize));
    $pageSize = $pageSize % 2 === 0 ? $pageSize : $pageSize + 1;
}

// 分页导航 pageNav()/pageLink()/getTotalPage() 读的都是 parameter->pageSize，必须同步
$obj->parameter->pageSize = $pageSize;
$currentPage = max(1, intval($obj->getCurrentPage()));

$po->order('table.contents.created', Db::SORT_DESC)
    ->page($currentPage, $pageSize);
$obj->query($po);
