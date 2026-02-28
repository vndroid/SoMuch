<?php

use Typecho\Db;
use Typecho\Config;
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
    ->join('table.relationships', 'table.relationships.cid = table.contents.cid', 'left')
    ->join('table.metas', 'table.relationships.mid = table.metas.mid', 'left')
    ->where("table.contents.password IS NULL OR table.contents.password = ''")
    ->where('table.contents.status = ?', 'publish')
    ->where(...$searchWhere)
    ->where('table.contents.type = ?', 'post')
    ->group('table.contents.cid');

$midFilter = $options->midFilter ?? null;
if ($midFilter) {
    $midFilter = array_unique(explode(',', $midFilter));
    foreach ($midFilter as $v) {
        $po = $po->where('table.relationships.mid != ' . intval($v));
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
