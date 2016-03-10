<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;

class AzulZabbixApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $apiUrl = 'http://10.0.100.55/zabbix/api_jsonrpc.php';
        $user = 'amix.reports';
        $password = getenv('AMIX_REPORTS_PASS');
        $this->api = new ZabbixApi($apiUrl, $user, $password);
    }

    public function testTriggersByHost1()
    {
        $api = $this->api;
        $triggers = $api->triggersByHost(
            'PRD-PAYMENTS',
            array('description' => array(
                '{HOSTNAME} - Verificar lentidão no ODS ao consultar pagamentos pendentes',
                '{HOSTNAME} - Verificar lentidão no ODS ao consultar pagamentos efetuados',
                '{HOSTNAME} - Verificar lentidão no ODS ao consultar pagamentos recusados'
            ))
        );
        $current = current($triggers);
        $this->assertGreaterThan(0, count($triggers));
        $this->assertObjectHasAttribute('triggerid', $current);
        $this->assertObjectHasAttribute('description', $current);
        $this->assertObjectHasAttribute('host', $current);
        $this->assertObjectHasAttribute('value', $current);
        $this->assertEquals('PRD-PAYMENTS', $current->host);
    }

    public function testTriggersByHostgroup1()
    {
        $api = $this->api;
        $triggers = $api->triggersByHostgroup(
            'AEROPORTOS',
            array('description' => array(
                '{HOSTNAME} - is Unreachable for {$A_PING_C}',
                '{HOSTNAME} is Unreachable'
            ))
        );
        $current = current($triggers);
        $this->assertGreaterThan(0, count($triggers));
        $this->assertObjectHasAttribute('triggerid', $current);
        $this->assertObjectHasAttribute('description', $current);
        $this->assertObjectHasAttribute('host', $current);
        $this->assertObjectHasAttribute('value', $current);
    }

    public function testTriggersByHostgroup2()
    {
        $api = $this->api;
        $triggers = $api->triggersByHostgroup(
            'AEROPORTOS-A',
            array('description' => array(
                '{HOSTNAME} - is Unreachable for {$A_PING_C}',
                '{HOSTNAME} is Unreachable'
            ))
        );
        $current = current($triggers);
        $this->assertGreaterThan(0, count($triggers));
        $this->assertObjectHasAttribute('triggerid', $current);
        $this->assertObjectHasAttribute('description', $current);
        $this->assertObjectHasAttribute('host', $current);
        $this->assertObjectHasAttribute('value', $current);
    }

    public function testTHAvailabilityByTriggers()
    {
        $api = $this->api;
        $triggers = $api->triggersByHostgroup(
            'AEROPORTOS',
            array('description' => array(
                '{HOSTNAME} - is Unreachable for {$A_PING_C}',
                '{HOSTNAME} is Unreachable'
            ))
        );
        $since = new \DateTime('2016-01-10 00:00:00');
        $until = new \DateTime('2016-01-12 00:00:00');
        $triggers = $this->api->availabilityByTriggers(
            $triggers,
            $since,
            $until
        );
        $current = current($triggers);
        $this->assertObjectHasAttribute('triggerid', $current);
        $this->assertObjectHasAttribute('description', $current);
        $this->assertObjectHasAttribute('host', $current);
        $this->assertObjectHasAttribute('expression', $current);
        $this->assertObjectHasAttribute('value', $current);
        $this->assertObjectHasAttribute('events', $current);
        $this->assertObjectHasAttribute('availability', $current);
        $this->assertInternalType('array', $current->events);
        $this->assertCount(237, $triggers);
    }
}
