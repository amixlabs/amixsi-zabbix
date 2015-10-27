<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;

class ZabbixApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $apiUrl = 'http://10.4.170.246/zabbix/api_jsonrpc.php';
        $user = 'amix';
        $password = 'rootroot';
        $this->api = new ZabbixApi($apiUrl, $user, $password);
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
        $this->assertInternalType('float', $current->availability);
        $this->assertEquals($disaster, $current->priority);
    }
}
