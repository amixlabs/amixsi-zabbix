<?php

namespace Amixsi\Zabbix;

use Psr\Log\LoggerInterface;
use Amixsi\Helper\DateRange;

class ZabbixApi extends \ZabbixApi
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
        $logger = $this->logger;
        $triggers = $this->availabilityByTriggers2($since, $until, $priorities);
        $triggers = array_filter($triggers, function ($trigger) {
            return $trigger->availability < 1;
        });
        usort($triggers, function ($trigger1, $trigger2) {
            return ($trigger1->availability > $trigger2->availability) ? 1 : -1;
        });
        return $triggers;
    }

    public function triggersByHostgroup($name)
    {
        $logger = $this->logger;

        $filter = array('name' => $name);

        if ($logger != null) {
            $logger->info('Zabbix get hostgroup for "{name}"', $filter);
        }
        $groups = $this->hostgroupGet(array(
            'output' => array('groupid', 'name'),
            'monitored_hosts' => true,
            'with_triggers' => true,
            'filter' => $filter
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
            'filter' => array(),
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
}
