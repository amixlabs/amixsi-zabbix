<?php

namespace Amixsi\Zabbix;

use Rych\ByteSize\ByteSize;

class Util
{
    public static function parseItemKeyValue($name)
    {
        if (preg_match('/(\d+)\s*-*\s*(.*)$/', $name, $matches)) {
            return array((int)$matches[1], $matches[2]);
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
                'hostid' => array('name' => 'hostid', 'lastvalue' => $first->hostid)
            );
            foreach ($items as $item) {
                $name = $item->parsedName[1];
                $group[$name] = array(
                    'name' => $name,
                    'lastvalue' => $item->lastvalue,
                    'itemid' => $item->itemid,
                    'lastclock' => $item->lastclock,
                    'prevvalue' => $item->prevvalue
                );
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
}
