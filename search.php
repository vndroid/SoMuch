<?php

use Typecho\Db;
use Typecho\Config;
use Utils\Helper;
use Widget\Archive;

/**
 * 以下变量由 Plugin::justSoSo() 通过 include 注入：
 *
 * @var string $keywords 原始关键词
 * @var string $searchQuery 处理后的搜索词（带 % 通配符）
 * @var int $soMode 搜索模式（1=标题及内容, 2=仅标题）
 * @var Archive $obj Archive Widget 实例
 * @var Config $options 插件配置对象
 */

$searchWhere = ($soMode == 2)
    ? ['table.contents.title LIKE ?', $searchQuery] // 仅标题
    : ['table.contents.title LIKE ? OR table.contents.text LIKE ?', $searchQuery, $searchQuery]; // 标题及内容

$po = $obj->select('table.contents.*')
    ->where("table.contents.password IS NULL OR table.contents.password = ''")
    ->where('table.contents.status = ?', 'publish')
    ->where('table.contents.created < ?', Helper::options()->time)
    ->where(...$searchWhere)
    ->where('table.contents.type = ?', 'post');

$midFilter = $options->midFilter ?? null;
if ($midFilter) {
    $midFilter = array_unique(array_filter(
        array_map('intval', explode(',', $midFilter)),
        static function ($mid) {
            return $mid > 0;
        }
    ));

    if ($midFilter) {
        $blockedMids = implode(',', $midFilter);
        $po->join(
            'table.relationships AS blocked_relationships',
            'blocked_relationships.cid = table.contents.cid'
            . ' AND blocked_relationships.mid IN (' . $blockedMids . ')',
            'left'
        )->join(
            'table.metas AS blocked_metas',
            "blocked_metas.mid = blocked_relationships.mid AND blocked_metas.type = 'category'",
            'left'
        )->where('blocked_metas.mid IS NULL');
    }
}

$se = clone $po;
$obj->setCountSql($se);

$page = $obj->request->get('page');

// 优先使用插件配置的 pageSize，否则用系统值并向上取整为偶数
$configPageSize = intval($options->pageSize ?? 0);
if ($configPageSize > 0) {
    $pageSize = $configPageSize % 2 === 0 ? $configPageSize : $configPageSize + 1;
} else {
    $sysPageSize = intval($obj->parameter->pageSize);
    $pageSize = $sysPageSize % 2 === 0 ? $sysPageSize : $sysPageSize + 1;
}

$po = $po->order('table.contents.created', Db::SORT_DESC)
    ->page($page, $pageSize);
$obj->query($po);

return $keywords;
