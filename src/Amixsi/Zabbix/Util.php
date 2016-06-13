<?php

namespace Amixsi\Zabbix;

use Rych\ByteSize\ByteSize;

class Util
{
    public static function parseItemKeyValue($item, $type = 'name')
    {
        if ($type == 'name') {
            if (preg_match('/(\d+)\s*-*\s*(.*)$/', $item, $matches)) {
                return array((int)$matches[1], $matches[2]);
            }
        } elseif ($type == 'key_') {
            if (preg_match('/([^\]]*)\[([^\]]*)\]$/', $item, $matches)) {
                return array($matches[2], $matches[1]);
            }
        }
        return false;
    }

    public static function groupItemsByParsedName($items)
    {
        $groups = array();
        foreach ($items as $item) {
            if (!isset($item->parsedName) || $item->parsedName === false) {
                continue;
            }
            list($groupid, $key) = $item->parsedName;
            $groupid .= '.' . $item->hostid;
            $group = isset($groups[$groupid]) ? $groups[$groupid] : array();
            $group[$key] = $item;
            $groups[$groupid] = $group;
        }
        return $groups;
    }

    public static function normalizeGroupItems($groupItems)
    {
        $groups = array();
        $commonKeys = null;
        foreach ($groupItems as $items) {
            $first = current($items);
            $group = array(
                'hostname' => array('name' => 'hostname', 'lastvalue' => $first->hosts[0]->name),
                'hostid' => array('name' => 'hostid', 'lastvalue' => $first->hostid),
                'hostip' => array('name' => 'hostip', 'lastvalue' => $first->interfaces[0]->ip)
            );
            foreach ($items as $item) {
                $name = $item->parsedName[1];
                $group[$name] = (array)$item;
                $group[$name]['name'] = $name;
            }
            $groups[] = $group;
            if ($commonKeys === null) {
                $commonKeys = array_keys($group);
            } else {
                $commonKeys = array_intersect($commonKeys, array_keys($group));
            }
        }
        return array(
            'groups' => $groups,
            'commonKeys' => $commonKeys
        );
    }

    public static function byteSize($value)
    {
        static $byteSize = null;
        if ($byteSize === null) {
            $byteSize = new ByteSize();
        }
        return $byteSize->format($value);
    }

    public static function topPerc($items, $perc, $compare)
    {
        $ret = usort($items, $compare);
        $count = count($items);
        if ($ret && $count > 0) {
            $offset = min(floor((1 - ($perc / 100)) * $count), $count - 1);
            return array_slice($items, $offset);
        }
        return false;
    }

    public static function arrayAverage($items, $get)
    {
        $count = count($items);
        if ($count > 0) {
            $values = array_map($get, $items);
            return array_sum($values) / $count;
        }
        return false;
    }

    public static function avgTopPerc($perc, $compare, $get)
    {
        return function ($items) use ($perc, $compare, $get) {
            $tops = Util::topPerc($items, $perc, $compare);
            if ($tops) {
                return Util::arrayAverage($tops, $get);
            }
            return false;
        };
    }
}
