<?php
// 根据搜索模式决定搜索字段条件
$searchWhere = ($soMode == 2)
    ? ['table.contents.title LIKE ?', $searchQuery]                                      // 仅标题
    : ['table.contents.title LIKE ? OR table.contents.text LIKE ?', $searchQuery, $searchQuery]; // 标题+正文

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

$po = $po->order('table.contents.created', Typecho_Db::SORT_DESC)
    ->page($page, $pageSize);
$obj->query($po);

return $keywords;
