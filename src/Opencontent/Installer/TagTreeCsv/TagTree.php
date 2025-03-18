<?php

namespace Opencontent\Installer\TagTreeCsv;

class TagTree
{
    /**
     * @var TagTreeItem[]
     */
    private $items;

    private $cache = [];

    public function __construct(array $items = null, TagTree $context = null)
    {
        if (!empty($items)) {
            $this->setItems($items, $context);
        }
    }

    public function count()
    {
        return count($this->items);
    }

    public function getRoot(): ?TagTreeItem
    {
        return $this->items[0] ?? null;
    }

    public function getHashList(): array
    {
        $hashList = [];
        foreach ($this->items as $item) {
            $hashList[] = $item->hash;
        }
        return $hashList;
    }

    public function setItems(array $items, TagTree $context = null)
    {
        foreach ($items as $item) {
            if ($item instanceof TagTreeItem) {
                if (!$item->getContext()){
                    $item->setContext($context ?? $this);
                }
                $this->items[] = $item;
            }
        }
    }

    public function getItems(): array
    {
        return $this->items ?? [];
    }

    public function getParentItem(TagTreeItem $child): ?TagTreeItem
    {
        if ($child->parentId === 0) {
            return null;
        }
        foreach ($this->items as $item) {
//            echo $item->keywords['it'] . ' ' . $item->id . ' ' . $child->parentId . PHP_EOL;
            if ($item->id === $child->parentId) {
                return $item;
            }
        }

        throw new \RuntimeException('Parent item not found in child ' . $child);
    }

    public function getByHash($hash): ?TagTreeItem
    {
        foreach ($this->items as $item) {
            if ($item->hash === $hash) {
                return $item;
            }
        }
        return null;
    }

    public function getRemoteIdList()
    {
        if (!isset($this->cache['remote'])) {
            $this->cache['remote'] = [];
            foreach ($this->items as $item) {
                $this->cache['remote'][json_encode($item)] = $item->remoteId;
            }
        }
        return $this->cache['remote'];
    }

    public function getKeywordList($locale = 'it')
    {
        if (!isset($this->cache['keyword-' . $locale])) {
            $charTransform = \eZCharTransform::instance();
            $this->cache['keyword-' . $locale] = [];
            $this->cache['keyword-trans-' . $locale] = [];
            foreach ($this->items as $item) {
                $this->cache['keyword-' . $locale][json_encode($item)] = $item->keywords[$locale];
                $this->cache['keyword-trans-' . $locale][json_encode($item)] = $charTransform
                    ->transformByGroup($item->keywords[$locale], 'identifier');
            }
        }
        return $this->cache['keyword-' . $locale];
    }

    public function refresh()
    {
        $this->cache = [];
    }

    public function findSimilar(TagTreeItem $external, $locale = 'it'): ?TagTreeItem
    {
        if ($item = array_search($external->remoteId, $this->getRemoteIdList())) {
            return TagTreeItem::fromJson($item, $this);
        }
        if ($item = array_search($external->keywords[$locale], $this->getKeywordList($locale))) {
            return TagTreeItem::fromJson($item, $this);
        }
        $charTransform = \eZCharTransform::instance();
        if ($item = array_search($charTransform
            ->transformByGroup($external->keywords[$locale], 'identifier'), $this->getKeywordList($locale))) {
            return TagTreeItem::fromJson($item, $this);
        }

        return null;

//        foreach ($this->items as $item) {
//            if ($item->remoteId === $external->remoteId) {
//                return $item;
//            }
//
//            if ($item->keywords[$locale] === $external->keywords[$locale]) {
//                return $item;
//            }
//
//            $charTransform = \eZCharTransform::instance();
//            if ($charTransform
//                    ->transformByGroup($item->keywords[$locale], 'identifier')
//                ===
//                $charTransform
//                    ->transformByGroup($external->keywords[$locale], 'identifier')) {
//                return $item;
//            }
//        }
//
//        return null;
    }
}