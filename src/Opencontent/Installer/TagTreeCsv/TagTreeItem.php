<?php

namespace Opencontent\Installer\TagTreeCsv;

use eZTagsObject;

class TagTreeItem
{
    public $id;

    public $parentId;

    public $remoteId;

    public $keywords = [];

    public $synonyms = [];

    public $descriptions = [];

    public $hash;

    private $path;

    /**
     * @var TagTree
     */
    private $context;

    public function jsonSerialize()
    {
        return [
            'id' => $this->id,
            'parentId' => $this->parentId,
            'remoteId' => $this->remoteId,
            'keywords' => $this->keywords,
            'synonyms' => $this->synonyms,
            'descriptions' => $this->descriptions,
            'hash' => $this->hash,
        ];
    }

    public static function fromJson(string $data, TagTree $context = null)
    {
        $row = json_decode($data, true);
        $item = new self();
        $item->id = (int)$row['id'] ?? self::throwException('id', $row);
        $item->parentId = (int)$row['parentId'] ?? self::throwException('parentId', $row);
        $item->remoteId = $row['remoteId'] ?? self::throwException('remoteId', $row);
        $item->hash = $row['hash'] ?? self::throwException('hash', $row);
        $item->keywords = $row['keywords'] ?? self::throwException('keywords', $row);
        $item->synonyms = $row['synonyms'] ?? self::throwException('synonyms', $row);
        $item->descriptions = $row['descriptions'] ?? self::throwException('descriptions', $row);
        if ($context) {
            $item->setContext($context);
        }
        return $item;
    }

    public static function fromArray(array $row, TagTree $context = null)
    {
        $item = new self();
        $item->id = (int)$row['id'] ?? self::throwException('id', $row);
        $item->parentId = (int)$row['parent_id'] ?? self::throwException('parent_id', $row);
        $item->remoteId = $row['remote_id'] ?? self::throwException('remote_id', $row);
        $item->hash = $row['hash'] ?? self::throwException('hash', $row);
        foreach ($row as $key => $value) {
            if (strpos($key, 'keyword') !== false) {
                [$keyword, $locale] = explode('_', $key);
                $item->keywords[$locale] = $value;
            }
            if (strpos($key, 'synonym') !== false) {
                [$synonym, $locale] = explode('_', $key);
                $item->synonyms[$locale] = $value;
            }
            if (strpos($key, 'description') !== false) {
                [$description, $locale] = explode('_', $key);
                $item->descriptions[$locale] = $value;
            }
        }
        if ($context) {
            $item->setContext($context);
        }
        return $item;
    }

    public function getContext(): ?TagTree
    {
        return $this->context;
    }

    public function setContext(TagTree $context): TagTreeItem
    {
        $this->context = $context;
        return $this;
    }

    private static function throwException($value, $row)
    {
        throw new \RuntimeException('Invalid value ' . $value . ' in  ' . var_export($row, true));
    }

    public function findParentTagObject(eZTagsObject $parentTagObject): ?eZTagsObject
    {
        $parent = $this->getParent();
        return $parent->findTagObject($parentTagObject);
    }

    public function findTagObject(eZTagsObject $parentTagObject): ?eZTagsObject
    {
        $tag = eZTagsObject::fetchByRemoteID($this->remoteId);
        if (!$tag instanceof eZTagsObject) {
            $tag = eZTagsObject::fetchByUrl($this->getPath());
        }
        return $tag;
    }

    public function getTagObject(): eZTagsObject
    {
        $tag = eZTagsObject::fetchByRemoteID($this->remoteId);
        if (!$tag instanceof eZTagsObject && $this->parentId === 0) {
            $roots = eZTagsObject::fetchList(
                ['parent_id' => 0, 'main_tag_id' => 0, 'keyword' => $this->keywords['it']]
            );
            $tag = $roots[0] ?? null;
        }
        if (!$tag instanceof eZTagsObject) {
            throw new \RuntimeException('eZTagsObject not found in ' . $this);
        }
        return $tag;
    }

    public function __toString(): string
    {
        return $this->keywords['it'] ?? $this->remoteId;
    }

    private function getParent(): TagTreeItem
    {
        return $this->context->getParentItem($this);
    }

    private function getAncestors(): TagTree
    {
        $items = [$this];
        $parent = $this->context->getParentItem($this);
        while ($parent) {
            $items[] = $parent;
            $parent = $this->context->getParentItem($parent);
        }
        $items = array_reverse($items);
        return new TagTree($items, $this->context);
    }

    public function getPath($locale = 'it'): string
    {
        if ($this->path === null) {
            $ancestors = $this->getAncestors($this->context);
            $path = [];
            foreach ($ancestors->getItems() as $item) {
                $path[] = $item->keywords[$locale];
            }

            $this->path = implode('/', $path);
        }

        return trim($this->path);
    }

    public function diff(TagTreeItem $item): string
    {
        $diff = [];
        if ($this->remoteId !== $item->remoteId) {
            $diff[] = '~r';
        }

        $subDiff = [];
        foreach ($this->keywords as $locale => $keyword) {
            if ($keyword !== $item->keywords[$locale]) {
                $subDiff[] = $locale;
            }
        }
        if (!empty($subDiff)) {
            $diff[] = "~k(".implode(',', $subDiff).")";
        }

        $subDiff = [];
        foreach ($this->synonyms as $locale => $synonym) {
            if ($synonym !== $item->synonyms[$locale]) {
                $subDiff[] = $locale;
            }
        }
        if (!empty($subDiff)) {
            $diff[] = "~s(".implode(',', $subDiff).")";
        }

        $subDiff = [];
        foreach ($this->descriptions as $locale => $description) {
            if ($description !== $item->descriptions[$locale]) {
                $subDiff[] = $locale;
            }
        }
        if (!empty($subDiff)) {
            $diff[] = "~s(".implode(',', $subDiff).")";
        }

        if ($this->getPath() !== $item->getPath()){
            $diff[] = '~p';
            $diff[] = $this->getPath();
            $diff[] = $item->getPath();
        }

        return implode(' ', $diff);
    }
}