<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;

class GolZabbixApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $apiUrl = 'http://10.4.170.246/zabbix/api_jsonrpc.php';
        $user = 'amix';
        $password = getenv('AMIX_PASS');
        $this->api = new ZabbixApi($apiUrl, $user, $password);
    }

    public function testGolLinkConsumption()
    {
        $api = $this->api;
        $triggers1 = $api->triggersSearch(array(
            'trigger' => 'Consumo do link',
            'expandDescription' => true
        ));
        $triggers2 = $api->triggersSearch(array(
            'trigger' => 'Trafego',
            'expandDescription' => true
        ));
        $triggers = array_merge($triggers1, $triggers2);
        $since = \DateTime::createFromFormat('Y-m-d H:i:s', '2016-04-01 00:00:00');
        $until = \DateTime::createFromFormat('Y-m-d H:i:s', '2016-04-03 00:00:00');
        $triggers = $api->eventDurationByTriggers($triggers, $since, $until, 3600);
        $this->assertGreaterThan(0, $triggers);
        foreach ($triggers as $trigger) {
            $this->assertObjectHasAttribute('triggerid', $trigger);
            $this->assertObjectHasAttribute('description', $trigger);
            $this->assertObjectHasAttribute('hosts', $trigger);
            $this->assertObjectHasAttribute('events', $trigger);
            $this->assertObjectHasAttribute('filteredEvents', $trigger);
        }
    }

    public function testGolVIPHighLatency()
    {
        $api = $this->api;
        $triggers = $api->triggersSearch(array(
            'trigger' => ' Latencia alta ',
            'expandDescription' => true
        ));
        $triggers = array_map(function ($trigger) {
            $trigger->vipHosts = array_filter($trigger->hosts, function ($host) {
                return strpos($host->name, 'VIP') !== false;
            });
            return $trigger;
        }, $triggers);
        $triggers = array_filter($triggers, function ($trigger) {
            return count($trigger->vipHosts) > 0;
        });
        $since = \DateTime::createFromFormat('Y-m-d H:i:s', '2016-04-01 00:00:00');
        $until = \DateTime::createFromFormat('Y-m-d H:i:s', '2016-04-03 00:00:00');
        $triggers = $api->eventDurationByTriggers($triggers, $since, $until, 15 * 60);
        $this->assertGreaterThan(0, $triggers);
        foreach ($triggers as $trigger) {
            $this->assertObjectHasAttribute('triggerid', $trigger);
            $this->assertObjectHasAttribute('description', $trigger);
            $this->assertObjectHasAttribute('hosts', $trigger);
            $this->assertObjectHasAttribute('events', $trigger);
            $this->assertObjectHasAttribute('filteredEvents', $trigger);
        }
    }

    public function testGolDownEvents01()
    {
        $api = $this->api;
        $since = \DateTime::createFromFormat('Y-m-d H:i:s', '2016-09-02 00:00:00');
        $until = \DateTime::createFromFormat('Y-m-d H:i:s', '2016-09-02 23:59:59');
        $priorities = array(4, 5);
        $triggers = $api->triggersSearch(array(
            'trigger' => '[003.398-X]',
            'filter' => array(
                'priority' => $priorities
            )
        ));
        $this->assertGreaterThan(0, $triggers);
        $triggers = $api->downEventsByTriggers($triggers, $since, $until, $priorities);
        $elapsed = 0;
        foreach ($triggers as $trigger) {
            $downEvents = $trigger->downEvents;
            foreach ($downEvents as $event) {
                $elapsed += $event->elapsed;
            }
        }
        $this->assertLessThanOrEqual(120, $elapsed);
    }
}
