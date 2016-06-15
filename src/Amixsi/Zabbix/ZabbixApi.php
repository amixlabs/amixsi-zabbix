<?php

namespace Amixsi\Zabbix;

use Psr\Log\LoggerInterface;
use Amixsi\Helper\DateRange;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ZabbixApi extends \ZabbixApi\ZabbixApi
{
    private $logger = null;

    public function __construct($apiUrl = '', $user = '', $password = '', LoggerInterface $logger = null)
    {
        parent::__construct($apiUrl, $user, $password);
        if ($logger != null) {
            $this->setLogger($logger);
        }
    }

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function calculateAvailability($events, \DateTime $since, \DateTime $until)
    {
        if (!count($events)) {
            // We need to look latest event until $since
            // to define if whole day was available or not
            $value = 0;
            // $previousEvents = $this->eventGet(array(
            //     'time_till' => $since->getTimestamp(),
            //     'output' => 'extend',
            //     'object' => 0,
            //     'source' => 0,
            //     'sortfield' => 'eventid',
            //     'sortorder' => 'DESC',
            //     'limit' => 1
            // ));
            // $previousEvent = current($previousEvents);
            // if ($previousEvent != null) {
            //     $value = $previousEvent->value;
            // }
            return $value == 0 ? 1 : 0;
        }
        $events = array_filter($events, function ($event) use ($since, $until) {
            return $since->getTimestamp() <= $event->clock && $event->clock <= $until->getTimestamp();
        });
        $elapsed = $until->getTimestamp() - $since->getTimestamp();
        $problem = $this->problemAccount($events, $since, $until);
        return ($elapsed - $problem) / $elapsed;
    }

    public function triggerByGroups($groups)
    {
        $logger = $this->logger;
        $groupIds = array_map(function ($group) {
            return $group->groupid;
        }, $groups);

        if ($logger != null) {
            $logger->info('Zabbix get triggers');
        }
        return $this->triggerGet(array(
            'output' => array('triggerid', 'description', 'expression', 'value'),
            'expandDescription' => true,
            'expandData' => true,
            'monitored' => true,
            'selectHosts' => 'extend',
            'filter' => array(),
            'groupids' => $groupIds,
            'hostids' => null,
            'limit' => 25000
        ));
    }

    public function triggerByPriorities($priorities)
    {
        $logger = $this->logger;
        if ($logger != null) {
            $logger->info('Zabbix get triggers by priorities({priorities})', array(
                'priorities' => implode(', ', $priorities)
            ));
        }
        return $this->triggerGet(array(
            'output' => array('triggerid', 'description', 'expression', 'priority', 'value'),
            'expandDescription' => true,
            'expandData' => true,
            'monitored' => true,
            'filter' => array('priority' => $priorities),
            'limit' => 25000
        ));
    }

    public function availabilityByTriggers($triggers, \DateTime $since, \DateTime $until)
    {
        $api = $this;
        $logger = $this->logger;
        $triggerIds = array_map(function ($trigger) {
            return $trigger->triggerid;
        }, $triggers);

        if ($logger != null) {
            $info = array(
                'since' => $since->format('d/m/Y H:i:s'),
                'until' => $until->format('d/m/Y H:i:s')
            );
            $logger->info('Zabbix get events [{since}, {until}]', $info);
        }
        $events = $this->eventGet(array(
            'triggerids' => $triggerIds,
            'time_from' => $since->getTimestamp(),
            'time_till' => $until->getTimestamp(),
            'output' => 'extend',
            'object' => 0,
            'source' => 0,
            'sortfield' => 'eventid'
        ));

        if ($logger != null) {
            $logger->debug('Calculating availability');
        }
        $triggers = array_map(function ($trigger) use ($events, $since, $until, $api) {
            $triggerEvents = array_filter($events, function ($event) use ($trigger) {
                return $event->objectid == $trigger->triggerid;
            });
            $trigger = clone $trigger;
            $trigger->availability = $api->calculateAvailability($triggerEvents, $since, $until);
            $trigger->events = $triggerEvents;
            return $trigger;
        }, $triggers);
        if ($logger != null) {
            $logger->debug('Availability calculated');
        }
        return $triggers;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function availabilityByTriggers2(\DateTime $since, \DateTime $until, $priorities = null)
    {
        $api = $this;
        $logger = $this->logger;
        if ($logger != null) {
            $info = array(
                'since' => $since->format('d/m/Y H:i:s'),
                'until' => $until->format('d/m/Y H:i:s')
            );
            $logger->info('Zabbix get events [{since}, {until}]', $info);
        }
        $events = $this->eventGet(array(
            'time_from' => $since->getTimestamp(),
            'time_till' => $until->getTimestamp(),
            'output' => 'extend',
            'object' => 0,
            'source' => 0,
            'sortfield' => 'eventid',
            'selectRelatedObject' => array(
                'triggerid', 'priority'
            )
        ));

        $priorities = (array)$priorities;
        if (count($priorities)) {
            if ($logger != null) {
                $logger->debug('Filter by priorities({priorities})', array(
                    'priorities' => implode(', ', $priorities)
                ));
            }
            $events = array_filter($events, function ($event) use ($priorities) {
                return in_array($event->relatedObject->priority, $priorities);
            });
        }

        if ($logger != null) {
            $logger->debug('Grouping by triggers');
        }
        $triggersEvents = array();
        foreach ($events as $event) {
            $trigger = $event->relatedObject;
            $triggerid = $trigger->triggerid;
            $event = clone $event;
            unset($event->relatedObject);
            if (isset($triggersEvents[$triggerid])) {
                $trigger = $triggersEvents[$triggerid];
                $trigger->events[] = $event;
            } else {
                $trigger = clone $trigger;
                $triggersEvents[$triggerid] = $trigger;
                $trigger->events = array($event);
            }
        }
        $triggerids = array_keys($triggersEvents);

        if ($logger != null) {
            $logger->info('Zabbix get related triggers with expanded description and host');
        }
        $triggers = $this->triggerGet(array(
            'output' => array('triggerid', 'description', 'expression', 'priority', 'value'),
            'expandDescription' => true,
            'expandData' => true,
            'monitored' => true,
            'filter' => array('triggerid' => $triggerids)
        ));

        if ($logger != null) {
            $logger->debug('Calculating availability');
        }
        $triggers = array_map(function ($trigger) use ($triggersEvents, $since, $until, $api) {
            $triggerEvents = $triggersEvents[$trigger->triggerid];
            $trigger->events = $triggerEvents->events;
            $trigger->availability = $api->calculateAvailability($triggerEvents->events, $since, $until);
            return $trigger;
        }, $triggers);
        if ($logger != null) {
            $logger->debug('Availability calculated');
        }
        return $triggers;
    }

    /**
     * @SuppressWarnings("unused")
     */
    public function eventsByTriggers($triggers, \DateTime $since, \DateTime $until)
    {
        $api = $this;
        $logger = $this->logger;
        $triggerIds = array_map(function ($trigger) {
            return $trigger->triggerid;
        }, $triggers);

        if ($logger != null) {
            $info = array(
                'since' => $since->format('d/m/Y H:i:s'),
                'until' => $until->format('d/m/Y H:i:s')
            );
            $logger->info('Zabbix get events [{since}, {until}]', $info);
        }
        $events = $this->eventGet(array(
            'triggerids' => $triggerIds,
            'time_from' => $since->getTimestamp(),
            'time_till' => $until->getTimestamp(),
            'output' => 'extend',
            'object' => 0,
            'source' => 0,
            'sortfield' => 'eventid'
        ));

        if ($logger != null) {
            $logger->debug('Grouping events by trigger');
        }

        $idxEvents = array();
        foreach ($events as $event) {
            if (isset($idxEvents[$event->objectid])) {
                $idxEvents[$event->objectid][] = $event;
            } else {
                $idxEvents[$event->objectid] = array($event);
            }
        }

        $triggers = array_map(function ($trigger) use ($idxEvents, $api) {
            $trigger = clone $trigger;
            if (isset($idxEvents[$trigger->triggerid])) {
                $trigger->events = $idxEvents[$trigger->triggerid];
            } else {
                $trigger->events = array();
            }
            return $trigger;
        }, $triggers);
        if ($logger != null) {
            $logger->debug('Events Grouped');
        }
        return $triggers;
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function filterEventsByDurationGreaterThen($events, $since, $until, $secs)
    {
        $filteredEvents = array();
        $elapsed = 0;
        $event = current($events);

        if ($event && $event->value == '0') {
            $previousEvents = $this->eventGet(array(
                'triggerids' => array($event->objectid),
                'eventid_till' => $event->eventid,
                'output' => array('eventid', 'value'),
                'object' => 0,
                'source' => 0,
                'limit' => 3
            ));
            $previousEvents = array_filter($previousEvents, function ($event) {
                return $event->value == '1';
            });
            //var_dump($previousEvents);
            // we know if the last event was a problem
            if (count($previousEvents) > 0) {
                $lastEvent = end($previousEvents);
                $elapsed = $event->clock - $since->getTimestamp();
                if ($elapsed >= $secs) {
                    $lastEvent->duration = $elapsed;
                    $filteredEvents[] = $lastEvent;
                }
            }
        }
        $lastEvent = clone $event;
        while ($event = next($events)) {
            if ($event->value == '0') {
                $elapsed = $event->clock - $lastEvent->clock;
                if ($elapsed >= $secs) {
                    $lastEvent->duration = $elapsed;
                    $filteredEvents[] = $lastEvent;
                }
            }
            $lastEvent = clone $event;
        }
        if ($lastEvent->value == '1') {
            $elapsed = $until->getTimestamp() - $lastEvent->clock;
            if ($elapsed >= $secs) {
                $lastEvent->duration = $elapsed;
                $filteredEvents[] = $lastEvent;
            }
        }
        return $filteredEvents;
    }

    public function routerReportGet(\DateTime $since, \DateTime $until, $availability = .95)
    {
        $logger = $this->logger;

        $filter = array('name' => 'Routers');

        if ($logger != null) {
            $logger->info('Zabbix get hostgroup for "{name}"', $filter);
        }
        $groups = $this->hostgroupGet(array(
            'output' => array('groupid', 'name'),
            'monitored_hosts' => true,
            'with_triggers' => true,
            'filter' => $filter
        ));

        $triggers = $this->triggerByGroups($groups);
        $triggers = array_filter($triggers, function ($trigger) {
            return stripos($trigger->description, 'Erros') !== false;
        });

        $triggers = $this->availabilityByTriggers($triggers, $since, $until);

        usort($triggers, function ($trigger1, $trigger2) {
            return ($trigger1->availability > $trigger2->availability) ? 1 : -1;
        });

        return array_filter($triggers, function ($trigger) use ($availability) {
            return $trigger->availability <= $availability;
        });
    }

    public function totemGroupGet()
    {
        $logger = $this->logger;
        if ($logger != null) {
            $logger->info('Zabbix get totem groups');
        }
        $groups = $this->hostgroupGet(array(
            'output' => array('groupid', 'name'),
            'monitored_hosts' => true,
            'with_triggers' => true
        ));
        return array_filter($groups, function ($group) {
            return preg_match('/-TOTENS$/', $group->name);
        });
    }

    public function totemReportGet(DateRange $dateRange)
    {
        $api = $this;
        $dates = $dateRange->getRange();
        $groups = $this->totemGroupGet();
        $triggers = $this->triggerByGroups($groups);
        $triggers = array_filter($triggers, function ($trigger) {
            return preg_match('/^\[001.640-B\] -/', $trigger->description);
        });
        return array_map(function ($date) use ($api, $triggers) {
            $since = $date;
            $until = clone $since;
            $interval = new \DateInterval('PT23H59M59S');
            $until->add($interval);
            $triggers = $api->availabilityByTriggers($triggers, $since, $until);
            return array(
                'date' => $date,
                'triggers' => $triggers
            );
        }, $dates);
    }

    public function vipRoutersReportGet(DateRange $dateRange)
    {
        $api = $this;
        $dates = $dateRange->getRange();
        $triggers = $this->triggersByHostgroup('Routers');
        $triggers = array_filter($triggers, function ($trigger) {
            return preg_match('/^\[000.020-C\].*VIP/i', $trigger->description);
        });
        return array_map(function ($date) use ($api, $triggers) {
            $since = $date;
            $until = clone $since;
            $interval = new \DateInterval('PT23H59M59S');
            $until->add($interval);
            $triggers = $api->availabilityByTriggers($triggers, $since, $until);
            return array(
                'date' => $date,
                'triggers' => $triggers
            );
        }, $dates);
    }

    private function problemAccount($events, \DateTime $since, \DateTime $until)
    {
        $problem = 0;
        $event = current($events);

        if ($event && $event->value == '0') {
            $previousEvents = $this->eventGet(array(
                'triggerids' => array($event->objectid),
                'eventid_till' => $event->eventid,
                'output' => array('eventid', 'value'),
                'object' => 0,
                'source' => 0,
                'limit' => 3
            ));
            $previousEvents = array_filter($previousEvents, function ($event) {
                return $event->value == '1';
            });
            //var_dump($previousEvents);
            // we know if the last event was a problem
            if (count($previousEvents) > 0) {
                $problem += $event->clock - $since->getTimestamp();
            }
        }
        $lastEvent = $event;
        while ($event = next($events)) {
            if ($event->value == '0') {
                $problem += $event->clock - $lastEvent->clock;
            }
            $lastEvent = $event;
        }
        if ($lastEvent->value == '1') {
            $problem += $until->getTimestamp() - $lastEvent->clock;
        }
        return $problem;
    }

    /**
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function triggerDisasterGet($limit = 0)
    {
        $priority = 5;
        $logger = $this->logger;
        $options = array(
            'monitored_hosts' => true,
            'monitored' => true,
            'selectHosts' => array('hostid', 'host'),
            'output' => array('triggerid', 'description'),
            'filter' => array('priority' => $priority),
            'sortfield' => array('description', 'hostname'),
            'limit' => $limit
        );
        $triggers = $this->triggerGet($options);
        if ($logger) {
            $logger->info('Zabbix triggers count={count}', array(
                'count' => count($triggers)
            ));
        }
        $options = array(
            'monitored_hosts' => true,
            'monitored' => true,
            'selectHosts' => array('hostid', 'host'),
            'output' => array('triggerid', 'description'),
            'filter' => array('priority' => $priority),
            'sortfield' => array('description'),
            'limit' => $limit
        );
        $prototypes = $this->triggerprototypeGet($options);
        if ($logger) {
            $logger->info('Zabbix triggers prototype count={count}', array(
                'count' => count($prototypes)
            ));
        }
        $codes = array();
        foreach ($triggers as $trigger) {
            $code = $this->getCodeFromDescription($trigger->description);
            if (array_key_exists($code, $codes)) {
                $hosts = array_merge($codes[$code]->hosts, $trigger->hosts);
                $codes[$code]->hosts = $hosts;
            } else {
                $codes[$code] = $trigger;
            }
        }
        foreach ($prototypes as $prototype) {
            $code = $this->getCodeFromDescription($prototype->description);
            if (array_key_exists($code, $codes)) {
                $codes[$code]->description = $prototype->description;
            }
        }
        $descriptionCompare = function ($host1, $host2) {
            return $host1->host > $host2->host ? 1 : -1;
        };
        foreach ($codes as $trigger) {
            usort($trigger->hosts, $descriptionCompare);
        }
        return array_values($codes);
    }

    public function getCodeFromDescription($description)
    {
        if (preg_match('/^\[([^\]]+)\]/', $description, $matches)) {
            return $matches[1];
        }
        return '';
    }

    public function triggerAlertDisasterGet($priorities = 5, $limit = 0)
    {
        return $this->triggerGet(array(
            'monitored_hosts' => true,
            'monitored' => true,
            'output' => array('triggerid', 'description'),
            'filter' => array('priority' => $priorities),
            'sortfield' => array('description'),
            'limit' => $limit
        ));
    }

    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function disasterReportGet(\DateTime $since, \DateTime $until, $priorities, $cacheFile = null)
    {
        $logger = $this->logger;
        $triggers = false;
        $readFromCache = false;
        if ($cacheFile !== null && is_readable($cacheFile)) {
            if ($logger != null) {
                $logger->info('Zabbix get triggers from cache');
            }
            $triggers = unserialize(file_get_contents($cacheFile));
            if ($logger != null) {
                $logger->info('Zabbix triggers count={count}', array(
                    'count' => count($triggers)
                ));
            }
            $readFromCache = true;
        }
        if ($triggers === false) {
            $triggers = $this->triggerByPriorities($priorities);
        }
        if ($cacheFile !== null && !$readFromCache) {
            if ($logger != null) {
                $logger->info('Zabbix save triggers to cache');
            }
            $size = file_put_contents($cacheFile, serialize($triggers));
            if ($logger != null) {
                $logger->info('Zabbix triggers cache saved. [size={size}]', array(
                    'size' => $size
                ));
            }
        }
        $triggers = $this->availabilityByTriggers($triggers, $since, $until);
        $triggers = array_filter($triggers, function ($trigger) {
            return $trigger->availability < 1;
        });
        usort($triggers, function ($trigger1, $trigger2) {
            return ($trigger1->availability > $trigger2->availability) ? 1 : -1;
        });
        return $triggers;
    }

    public function disasterReportGet2(\DateTime $since, \DateTime $until, $priorities)
    {
        $triggers = $this->availabilityByTriggers2($since, $until, $priorities);
        $triggers = array_filter($triggers, function ($trigger) {
            return $trigger->availability < 1;
        });
        usort($triggers, function ($trigger1, $trigger2) {
            return ($trigger1->availability > $trigger2->availability) ? 1 : -1;
        });
        return $triggers;
    }

    public function triggersByHost($name, $filter = array())
    {
        $logger = $this->logger;

        $hostFilter = array('name' => $name);

        if ($logger != null) {
            $logger->info('Zabbix get host for "{name}"', array(
                'name' => implode(', ', (array)$name)
            ));
        }
        $hosts = $this->hostGet(array(
            'output' => array('hostid', 'name'),
            'monitored_hosts' => true,
            'with_triggers' => true,
            'filter' => $hostFilter
        ));

        $hostIds = array_map(function ($host) {
            return $host->hostid;
        }, $hosts);


        return $this->triggerGet(array(
            'output' => array('triggerid', 'description', 'expression', 'value'),
            'expandDescription' => true,
            'expandData' => true,
            'monitored' => true,
            'selectHosts' => 'extend',
            'filter' => $filter,
            'groupids' => null,
            'hostids' => $hostIds,
            'limit' => 25000
        ));
    }

    public function triggersByHostgroup($name, $filter = array())
    {
        $logger = $this->logger;

        $hostgroupFilter = array('name' => $name);

        if ($logger != null) {
            $logger->info('Zabbix get hostgroup for "{name}"', array(
                'name' => implode(', ', (array)$name)
            ));
        }
        $groups = $this->hostgroupGet(array(
            'output' => array('groupid', 'name'),
            'monitored_hosts' => true,
            'with_triggers' => true,
            'filter' => $hostgroupFilter
        ));

        $groupIds = array_map(function ($group) {
            return $group->groupid;
        }, $groups);


        return $this->triggerGet(array(
            'output' => array('triggerid', 'description', 'expression', 'value'),
            'expandDescription' => true,
            'expandData' => true,
            'monitored' => true,
            'selectHosts' => 'extend',
            'filter' => $filter,
            'groupids' => $groupIds,
            'hostids' => null,
            'limit' => 25000
        ));
    }

    public function historyItemGet($item, \DateTime $since, \DateTime $until)
    {
        $logger = $this->logger;
        if ($logger != null) {
            $logger->info('Zabbix get history for item "{name}"', array(
                'name' => $item->name
            ));
        }
        $history = $this->historyGet(array(
            'itemids' => $item->itemid,
            'time_from' => $since->getTimestamp(),
            'time_till' => $until->getTimestamp(),
            'sortfield' => 'clock',
            'output' => 'extend'
        ));
        $item->history = $history;
        return $item;
    }

    public function historyItemsGet($items, \DateTime $since, \DateTime $until)
    {
        $api = $this;
        return array_map(function ($item) use ($api, $since, $until) {
            return $api->historyItemGet($item, $since, $until);
        }, $items);
    }

    public function trafficReportGet($hostsItems, \DateTime $since, \DateTime $until)
    {
        $logger = $this->logger;
        $api = $this;
        $hostsItems = array_map(function ($host) use ($logger, $api, $since, $until) {
            $filter = array('name' => $host['name']);
            if ($logger != null) {
                $logger->info('Zabbix get host "{name}"', $filter);
            }
            $hosts = $this->hostGet(array(
                'output' => array('hostid', 'name'),
                'monitored_hosts' => true,
                'with_items' => true,
                'selectItems' => 'extend',
                /*
                array(
                    'itemid',
                    'delay',
                    'name',
                    'value_type',
                    'delta',
                    'history',
                    'status',
                    'units'
                ),
                 */
                'filter' => $filter
            ));
            if (!count($hosts)) {
                return null;
            }
            $result = $hosts[0];
            //if ($result->name == 'BIGIP-EXT-01') {
                //print_r($result);
            //}
            $items = array_filter($result->items, function ($item) use ($host) {
                $hasKeys = isset($host['keys']);
                if ($hasKeys) {
                    $index = array_search($item->key_, $host['keys']);
                    $inArray = $index !== false;
                    $item->name = $host['items'][$index];
                } else {
                    $inArray = in_array($item->name, $host['items']);
                }
                $isEnabled = $item->status == 0;
                return $inArray && $isEnabled;
            });
            $items = $api->historyItemsGet($items, $since, $until);
            $result->items = array_values($items);
            return $result;
        }, $hostsItems);
        return $hostsItems;
    }

    public function apiinfoVersion($params = array(), $arrayKeyProperty = '')
    {
        return $this->request('apiinfo.version', $params, $arrayKeyProperty, false);
    }

    public function groupItemApplicationGet(array $params, $field = 'name')
    {
        $params = array_merge($params, array(
            'selectHosts' => array('name'),
            'selectInterfaces' => array('ip'),
            'output' => array(
                'itemid',
                'hostid',
                'key_',
                'name',
                'value_type',
                'units',
                'lastclock',
                'lastvalue',
                'prevvalue'
            )
        ));
        $items = $this->itemGet($params);
        $items = array_map(function ($item) use ($field) {
            $item->parsedName = Util::parseItemKeyValue($item->$field, $field);
            if ($item->units == 'B') {
                $item->lastvalue = Util::byteSize($item->lastvalue);
                $item->prevvalue = Util::byteSize($item->prevvalue);
            }
            return $item;
        }, $items);
        $items = Util::groupItemsByParsedName($items);
        $groups = Util::normalizeGroupItems($items);
        //var_dump($items);
        return $groups;
    }

    protected function groupidsSearch($name)
    {
        $groups = $this->hostgroupGet(array(
            'output' => array('groupid'),
            'monitored_hosts' => true,
            'with_triggers' => true,
            'search' => array('name' => $name)
        ));
        return array_map(function ($group) {
            return $group->groupid;
        }, $groups);
    }

    public function triggersSearch(array $params)
    {
        $params2 = array(
            'output' => array('triggerid', 'description'),
            'monitored' => true,
            'selectHosts' => array('name')
        );
        if (isset($params['group'])) {
            $params2['groupids'] = $this->groupidsSearch($params['group']);
        }
        if (isset($params['trigger'])) {
            $params2['search'] = array('description' => $params['trigger']);
        }
        if (isset($params['expandDescription'])) {
            $params2['expandDescription'] = true;
        }
        return $this->triggerGet($params2);
    }

    public function downEventsByTriggers($triggers)
    {
        $api = $this;
        $logger = $this->logger;
        $triggerIds = array_map(function ($trigger) {
            return $trigger->triggerid;
        }, $triggers);

        if ($logger != null) {
            $logger->info('Zabbix get events by triggerids');
        }
        $events = $this->eventGet(array(
            'triggerids' => $triggerIds,
            'output' => 'extend',
            'object' => 0,
            'source' => 0,
            'sortfield' => 'eventid'
        ));

        if ($logger != null) {
            $logger->debug('Calculating availability');
        }
        $triggers = array_map(function ($trigger) use ($events, $api) {
            $triggerEvents = array_filter($events, function ($event) use ($trigger) {
                return $event->objectid == $trigger->triggerid;
            });
            $trigger = clone $trigger;
            $trigger->downEvents = $api->calculateDownEvents($triggerEvents);
            $trigger->events = $triggerEvents;
            return $trigger;
        }, $triggers);
        if ($logger != null) {
            $logger->debug('Down events calculated');
        }
        return $triggers;
    }

    public function calculateDownEvents($events)
    {
        $until = new \DateTime();
        $downs = array();
        $lastEvent = current($events);
        while ($event = next($events)) {
            if ($lastEvent->value == '1') {
                $lastEvent->elapsed = abs($event->clock - $lastEvent->clock);
                $lastEvent->event = $event;
                $downs[] = $lastEvent;
            }
            $lastEvent = $event;
        }
        if ($lastEvent && $lastEvent->value == '1') {
            $lastEvent->elapsed = $until->getTimestamp() - $lastEvent->clock;
            $downs[] = $lastEvent;
        }
        return $downs;
    }

    public function historyHost($name, \DateTime $since, \DateTime $until)
    {
        $hosts = $this->hostGet(array(
            'output' => array('hostid'),
            'filter' => array('name' => $name)
        ));
        $hostids = array_map(function ($data) {
            return $data->hostid;
        }, $hosts);
        return $this->historyGet(array(
            'hostids' => $hostids,
            'time_from' => $since->getTimestamp(),
            'time_till' => $until->getTimestamp(),
            'output' => 'extend',
            'sortfield' => 'clock'
        ));
    }

    public function coreHost($servers)
    {
        $cores = array();

        foreach ($servers as $server) {
            $optGroup = array(
                'output' => array('groupid', 'name'),
                'monitored_hosts' => true,
                'filter' => array('name' => $server)
            );
            $group = $this->hostgroupGet($optGroup);

            if ($server == 'PRD-VCENTER-HOSTS-DC') {
                $itemSearch = 'check_vcenter.sh[{$VC_IP},{HOST.HOST},h.cpucore]';
            } else {
                $itemSearch = 'system.uname';
            }

            $optItem = array(
                'output'=>array('name','key_','lastvalue'),
                'groupids'=>$group[0]->groupid,
                'selectHosts'=>array('hostid','host','name'),
                'filter'=>array('key_'=>$itemSearch),
            );
            $hosts = $this->itemGet($optItem);

            $itemHost = array();
            foreach ($hosts as $host) {
                $itens = array(
                    array(
                        'key'=>$host->key_,
                        'key_desc'=>$host->name,
                        'value'=>$host->lastvalue
                    )
                );

                if ($server != 'PRD-VCENTER-HOSTS-DC') {
                    $optItem2 = array(
                        'output'=>array('name','key_','lastvalue'),
                        'hostids'=>$host->hosts[0]->hostid,
                        'search'=>array('key_'=>'system.cpu.num'),
                    );
                    $item2 = $this->itemGet($optItem2);

                    array_push(
                        $itens,
                        array(
                            'key'=>$item2[0]->key_,
                            'key_desc'=>$item2[0]->name,
                            'value'=>$item2[0]->lastvalue
                        )
                    );
                }

                $itemHost[] = array(
                    'hostid'=>$host->hosts[0]->hostid,
                    'host'=>$host->hosts[0]->host,
                    'name'=>$host->hosts[0]->name,
                    'itens'=>$itens
                );
            }

            $cores[] = array('groupid'=>$group[0]->groupid, 'name'=>$group[0]->name, 'hosts'=>$itemHost);
        }

        return $cores;
    }

    public function maintenanceList($filter = null)
    {
        $options = array(
            'output' => 'extend',
            'selectGroups' => 'extend',
            'selectHosts' => 'extend',
            'selectTimeperiods' => 'extend'
        );
        if ($filter != null) {
            $options['filter'] = (array)$filter;
        }
        return $this->maintenanceGet($options);
    }

    public function maintenanceCreate($params = array(), $arrayKeyProperty = '')
    {
        $item = $this->normalizeMaintenanceData($params);
        return parent::maintenanceCreate($item, $arrayKeyProperty);
    }

    public function maintenanceUpdate($params = array(), $arrayKeyProperty = '')
    {
        $item = $this->normalizeMaintenanceData($params);
        return parent::maintenanceUpdate($item, $arrayKeyProperty);
    }

    private function normalizeMaintenanceData($params)
    {
        $item = (array)$params;
        $groupids = array_map(function ($group) {
            $group = (array)$group;
            return $group['groupid'];
        }, $item['groups']);
        $hostids = array_map(function ($host) {
            $host = (array)$host;
            return $host['hostid'];
        }, $item['hosts']);
        $timeperiods = array_map(function ($timeperiod) {
            $timeperiod = (array)$timeperiod;
            unset($timeperiod['timeperiodid']);
            return $timeperiod;
        }, $item['timeperiods']);
        $item['groupids'] = $groupids;
        $item['hostids'] = $hostids;
        unset($item['groups']);
        unset($item['hosts']);
        $item['timeperiods'] = $timeperiods;
        return $item;
    }

    public function eventDurationByTriggers($triggers, \DateTime $since, \DateTime $until, $minSeconds)
    {
        $api = $this;
        $triggers = $api->eventsByTriggers($triggers, $since, $until);
        $triggers = array_filter($triggers, function ($trigger) {
            return count($trigger->events) > 0;
        });
        $triggers = array_map(function ($trigger) use ($api, $since, $until, $minSeconds) {
            $events = $api->filterEventsByDurationGreaterThen($trigger->events, $since, $until, $minSeconds);
            $trigger->filteredEvents = $events;
            return $trigger;
        }, $triggers);
        $triggers = array_filter($triggers, function ($trigger) {
            return count($trigger->filteredEvents) > 0;
        });
        return $triggers;
    }

    protected function getHostsIndexed($output = null)
    {
        if ($output == null) {
            $output = array('hostid', 'host');
        }
        $hosts = $this->hostGet(array(
            'output' => $output
        ));
        $idxHosts = array();
        foreach ($hosts as $host) {
            $idxHosts[$host->host] = $host;
        }
        return $idxHosts;
    }

    protected function getHostGroupsIndexed($output = null)
    {
        if ($output == null) {
            $output = array('groupid', 'name');
        }
        $groups = $this->hostgroupGet(array(
            'output' => $output
        ));
        $idxGroups = array();
        foreach ($groups as $group) {
            $idxGroups[$group->name] = $group;
        }
        return $idxGroups;
    }

    public function createHostsIfNotExists($hosts)
    {
        $idxHosts = $this->getHostsIndexed();
        $idxGroups = $this->getHostGroupsIndexed();
        $insertHosts = array_filter($hosts, function ($host) use ($idxHosts) {
            return !isset($idxHosts[$host['host']]);
        });
        $resultHosts = array_map(function ($host) use ($idxGroups) {
            $exception = null;
            $result = null;
            try {
                $host['groups'] = array_map(function ($groupname) use ($idxGroups) {
                    if (!isset($idxGroups[$groupname])) {
                        throw new Exception("Group $groupname does not exists");
                    }
                    $group = $idxGroups[$groupname];
                    return array('groupid' => $group->groupid);
                }, $host['groups']);
                $result = $this->hostCreate($host);
            } catch (\Exception $e) {
                $exception = $e->getMessage();
            }
            return array(
                'host' => $host,
                'result' => $result,
                'exception' => $exception
            );
        }, $insertHosts);
        return $resultHosts;
    }

    public function itemsReportByGroupApp($group, $app, $appLimit = null)
    {
        $logger = $this->logger;

        if ($logger != null) {
            $logger->info('Zabbix get host groups');
        }
        $groups = $this->hostgroupGet(array(
            'output' => array('groupid'),
            'filter' => array(
                'name' => $group
            )
        ));
        $groupids = array_map(function ($group) {
            return $group->groupid;
        }, $groups);

        if ($logger != null) {
            $logger->info('Zabbix get applications');
        }
        $apps = $this->applicationGet(array(
            'groupids' => $groupids,
            'output' => array('applicationid'),
            'filter' => array(
                'name' => $app
            ),
            'limit' => $appLimit
        ));
        $appids = array_map(function ($app) {
            return $app->applicationid;
        }, $apps);

        if ($logger != null) {
            $logger->info('Zabbix get items');
        }
        return $this->groupItemApplicationGet(array(
            'applicationids' => $appids,
        ), 'key_');
    }

    public function mapReduceItemHistory($item, $since, $until, $functions)
    {
        $logger = $this->logger;
        $histories = array();
        try {
            $histories = $this->historyGet(array(
                'itemids' => $item['itemid'],
                'time_from' => $since->getTimestamp(),
                'time_till' => $until->getTimestamp(),
                'output' => 'extend'
            ));
        } catch (Exception $e) {
            if ($logger != null) {
                $logger->warning('historyGet([ "item" => '. $item['itemid'] . ' ]): ' . $e->getMessage());
            }
        }
        $values = array_map(function ($reduce) use ($histories, $item, $since, $until) {
            return call_user_func($reduce, $histories, $item, $since, $until);
        }, $functions);
        return array_merge($item, $values);
    }
}
