<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

class ZabbixApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $apiUrl = 'http://10.4.170.246/zabbix/api_jsonrpc.php';
        $user = 'amix';
        $password = getenv('AMIX_PASS');
        $logger = null;
        if (getenv('LOG')) {
            $logger = new Logger('test');
            $logger->pushProcessor(new PsrLogMessageProcessor());
        }
        $this->api = new ZabbixApi($apiUrl, $user, $password, $logger);
    }

    public function testRouterReportGet()
    {
        $since = new \DateTime('yesterday');
        $since->add(new \DateInterval('PT8H'));
        $until = clone $since;
        $until->add(new \DateInterval('PT03H59M59S'));
        $triggers = $this->api->routerReportGet($since, $until);
        $triggers = array_filter($triggers, function ($trigger) {
            foreach (array('Serial', 'FastEthernet', 'GigabitEthernet') as $word) {
                if (stripos($trigger->description, $word) !== false) {
                    return true;
                }
                return false;
            }
        });
        $this->assertGreaterThan(0, count($triggers));
    }

    public function testPriorityEventGet()
    {
        $since = new \DateTime('yesterday');
        $since->add(new \DateInterval('PT8H'));
        $until = clone $since;
        $until->add(new \DateInterval('PT03H59M59S'));
        $events = $this->api->eventGet(array(
            'time_from' => $since->getTimestamp(),
            'time_till' => $until->getTimestamp(),
            'output' => 'extend',
            'object' => 0,
            'source' => 0,
            'sortfield' => 'eventid',
            'selectRelatedObject' => array(
                'triggerid', 'description', 'expression', 'priority', 'value'
            )
        ));
        $current = current($events);
        $this->assertObjectHasAttribute('relatedObject', $current);
        $this->assertObjectHasAttribute('triggerid', $current->relatedObject);
        $this->assertObjectHasAttribute('description', $current->relatedObject);
        $this->assertObjectHasAttribute('expression', $current->relatedObject);
        $this->assertObjectHasAttribute('priority', $current->relatedObject);
        $this->assertObjectHasAttribute('value', $current->relatedObject);
    }

    public function testNormalizeEvents()
    {
        $event = new \stdClass();
        $event->objectid = 71165;
        $event->clock = 1509503407;
        $since = new \DateTime('2017-11-01 00:00:00');
        $until = new \DateTime('2017-11-01 23:59:59');
        $events = array($event);
        $normalizedEvents = $this->api->normalizeEvents(
            $events,
            $since,
            $until
        );
        $this->assertEquals(2, count($normalizedEvents));
        foreach ($normalizedEvents as $normalizedEvent) {
            $this->assertObjectHasAttribute('objectid', $normalizedEvent);
            $this->assertObjectHasAttribute('clock', $normalizedEvent);
            $this->assertObjectHasAttribute('elapsed', $normalizedEvent);
        }
    }

    public function testAvailabilityByTriggers2()
    {
        $since = new \DateTime('yesterday');
        $since->add(new \DateInterval('PT8H'));
        $until = clone $since;
        $until->add(new \DateInterval('PT03H59M59S'));
        $disaster = ZabbixPriority::fromString('disaster');
        $triggers = $this->api->availabilityByTriggers2(
            $since,
            $until,
            $disaster
        );
        $current = current($triggers);
        $this->assertObjectHasAttribute('triggerid', $current);
        $this->assertObjectHasAttribute('description', $current);
        $this->assertObjectHasAttribute('host', $current);
        $this->assertObjectHasAttribute('expression', $current);
        $this->assertObjectHasAttribute('priority', $current);
        $this->assertObjectHasAttribute('value', $current);
        $this->assertObjectHasAttribute('events', $current);
        $this->assertObjectHasAttribute('availability', $current);
        $this->assertInternalType('array', $current->events);
        $this->assertTrue(is_numeric($current->availability));
        $this->assertEquals($disaster, $current->priority);
    }

    public function testAvailabilityByTriggers2WithExcludeHostgroup()
    {
        $since = new \DateTime('yesterday');
        $since->add(new \DateInterval('PT8H'));
        $until = clone $since;
        $until->add(new \DateInterval('PT03H59M59S'));
        $disaster = ZabbixPriority::fromString('disaster');
        $options = array(
            'excludeHostgroups' => array(
                'DESENVOLVIMENTO',
                'HOMOLOGACAO',
            )
        );
        $triggers = $this->api->availabilityByTriggers2(
            $since,
            $until,
            $disaster,
            $options
        );
        $current = current($triggers);
        $this->assertObjectHasAttribute('triggerid', $current);
        $this->assertObjectHasAttribute('description', $current);
        $this->assertObjectHasAttribute('host', $current);
        $this->assertObjectHasAttribute('expression', $current);
        $this->assertObjectHasAttribute('priority', $current);
        $this->assertObjectHasAttribute('value', $current);
        $this->assertObjectHasAttribute('events', $current);
        $this->assertObjectHasAttribute('availability', $current);
        $this->assertObjectHasAttribute('groups', $current);
        $this->assertInternalType('array', $current->events);
        $this->assertTrue(is_numeric($current->availability));
        $this->assertEquals($disaster, $current->priority);
        $this->assertGreaterThan(0, count($current->groups));
        $group = $current->groups[0];
        $options['excludeHostgroups'][] = $group->name;
        $triggers2 = $this->api->availabilityByTriggers2(
            $since,
            $until,
            $disaster,
            $options
        );
        $this->assertGreaterThan(count($triggers2), count($triggers));
    }

    public function testApplication()
    {
        $apps = $this->api->applicationGet(array(
            'hostids' => '13867',
            'output' => 'extend',
            'search' => array(
                'name' => 'mdiskgrp'
            )
        ));
        $this->assertCount(1, $apps);
        $this->assertObjectHasAttribute('applicationid', $apps[0]);
        $this->assertInternalType('array', $apps[0]->templateids);
    }

    public function testTriggersSearch()
    {
        $triggers = $this->api->triggersSearch(array(
            'group' => 'Linux Servers',
            'trigger' => '001.296-C'
        ));
        $this->assertGreaterThan(0, $triggers);
        foreach ($triggers as $trigger) {
            $this->assertObjectHasAttribute('triggerid', $trigger);
            $this->assertObjectHasAttribute('description', $trigger);
            $this->assertObjectHasAttribute('hosts', $trigger);
        }
    }

    public function testDownEventsByTriggers()
    {
        //$triggers = array((object)array('triggerid' => '83880'));
        $triggers = $this->api->triggersSearch(array(
            'group' => 'Linux Servers',
            'trigger' => '001.296-C'
        ));
        $triggersDownEvents = $this->api->downEventsByTriggers($triggers);
        $this->assertGreaterThan(0, $triggersDownEvents);
        foreach ($triggersDownEvents as $triggerDownEvents) {
            $this->assertObjectHasAttribute('triggerid', $triggerDownEvents);
            $this->assertObjectHasAttribute('downEvents', $triggerDownEvents);
            $downEvents = $triggerDownEvents->downEvents;
            $this->assertGreaterThan(0, $downEvents);
            foreach ($downEvents as $downEvent) {
                $traceData = var_export(array(
                    'triggerid' => $triggerDownEvents->triggerid,
                    'hosts' => $triggerDownEvents->hosts,
                    'downEvent' => (array)$downEvent
                ), true);
                $this->assertObjectHasAttribute('value', $downEvent);
                $this->assertObjectHasAttribute('clock', $downEvent);
                $this->assertObjectHasAttribute('elapsed', $downEvent);
                $this->assertEquals('1', $downEvent->value, 'Wrong downEvent->value. '.$traceData);
                $this->assertGreaterThan(0, $downEvent->elapsed, 'Wrong downEvent->elapsed. '.$traceData);
            }
        }
    }

    public function testDownEventsByTriggers2()
    {
        $since = new \DateTime('yesterday');
        $since->add(new \DateInterval('PT8H'));
        $until = clone $since;
        $until->add(new \DateInterval('PT03H59M59S'));
        $triggers = $this->api->triggersSearch(array(
            'trigger' => '-O]'
        ));
        $triggersDownEvents = $this->api->downEventsByTriggers($triggers, $since, $until);
        $this->assertGreaterThan(0, $triggersDownEvents);
        foreach ($triggersDownEvents as $triggerDownEvents) {
            $this->assertObjectHasAttribute('triggerid', $triggerDownEvents);
            $this->assertObjectHasAttribute('priority', $triggerDownEvents);
            $this->assertObjectHasAttribute('downEvents', $triggerDownEvents);
            $downEvents = $triggerDownEvents->downEvents;
            $this->assertGreaterThan(0, $downEvents);
            foreach ($downEvents as $downEvent) {
                $traceData = var_export(array(
                    'triggerid' => $triggerDownEvents->triggerid,
                    'hosts' => $triggerDownEvents->hosts,
                    'downEvent' => (array)$downEvent
                ), true);
                $this->assertObjectHasAttribute('value', $downEvent);
                $this->assertObjectHasAttribute('clock', $downEvent);
                $this->assertObjectHasAttribute('elapsed', $downEvent);
                $this->assertEquals('1', $downEvent->value, 'Wrong downEvent->value. '.$traceData);
                $this->assertGreaterThanOrEqual(0, $downEvent->elapsed, 'Wrong downEvent->elapsed. '.$traceData);
            }
        }
    }

    public function testHistoryHost()
    {
        $name = 'PRD-ZABBIX_DB-GOLL1007';
        $since = new \DateTime();
        $until = clone $since;
        $since->sub(new \DateInterval('PT10M'));
        $items = $this->api->historyHost($name, $since, $until);
        $this->assertGreaterThan(0, $items);
        $item = $items[0];
        $this->assertObjectHasAttribute('itemid', $item);
        $this->assertObjectHasAttribute('clock', $item);
        $this->assertObjectHasAttribute('value', $item);
        $this->assertObjectHasAttribute('ns', $item);
    }
}
