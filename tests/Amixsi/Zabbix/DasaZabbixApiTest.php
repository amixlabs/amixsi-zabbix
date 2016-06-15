<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\PsrLogMessageProcessor;

class DasaZabbixApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $logger = null;
        //$logger = new Logger('test');
        //$logger->pushProcessor(new PsrLogMessageProcessor());
        $apiUrl = 'http://10.253.14.129/zabbix/api_jsonrpc.php';
        $user = getenv('DASA_USER');
        $password = getenv('DASA_PASS');
        $this->api = new ZabbixApi($apiUrl, $user, $password, $logger);
    }

    public function testDasaCreateHostsIfNotExists()
    {
        $api = $this->api;

        $hosts = array(
            array(
                'host' => '109-SERGIOFRANCO-RT-001',
                'groups' => array('DEFAULT-SECURITY-GROUP'),
                'interfaces' => array(
                    array(
                        'type' => 1, // agent
                        'main' => 1,
                        'useip' => 1,
                        'ip' => '10.23.46.254',
                        'dns' => '',
                        'port' => '10050'
                    ),
                    array(
                        'type' => 2, // SNMP
                        'main' => 1,
                        'useip' => 1,
                        'ip' => '10.23.46.254',
                        'dns' => '',
                        'port' => '161'
                    )
                )
            )
        );

        $hosts = $api->createHostsIfNotExists($hosts);
        $this->assertCount(0, $hosts);
    }

    public function testDasaApplication()
    {
        $apps = $this->api->applicationGet(array(
            'hostids' => '10188',
            'output' => 'extend',
            'search' => array(
                'name' => 'Interfaces'
            )
        ));
        $this->assertCount(1, $apps);
        $this->assertObjectHasAttribute('applicationid', $apps[0]);
        $this->assertInternalType('array', $apps[0]->templateids);
        $this->assertEquals('592', $apps[0]->applicationid);
    }

    public function testDataGroupItemApplication()
    {
        $groupItems = $this->api->groupItemApplicationGet(array(
            'applicationids' => array(592),
        ), 'key_');
        $this->assertArrayHasKey('groups', $groupItems);
        $this->assertArrayHasKey('commonKeys', $groupItems);
        $groups = $groupItems['groups'];
        $commonKeys = $groupItems['commonKeys'];
        $this->assertInternalType('array', $groups);
        $this->assertInternalType('array', $commonKeys);
        $expectedCommonKeys = array(
            "hostname",
            "hostid",
            "hostip",
            "ifAdminStatus",
            "ifAlias",
            "ifDescr",
            "ifHCInOctets",
            "ifHCOutOctets",
            "ifInDiscards",
            "ifInErrors",
            "ifMtu",
            "ifOperStatus",
            "ifOutDiscards",
            "ifOutErrors",
            "ifSpeed"
        );
        sort($expectedCommonKeys);
        sort($commonKeys);
        $this->assertEquals($expectedCommonKeys, $commonKeys);
        $group = $groups[0];
        foreach ($commonKeys as $key) {
            $this->assertArrayHasKey($key, $group);
            $item = $group[$key];
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('lastvalue', $item);
            if (!in_array($key, array('hostname', 'hostid', 'hostip'))) {
                $this->assertArrayHasKey('itemid', $item);
                $this->assertArrayHasKey('lastclock', $item);
                $this->assertArrayHasKey('prevvalue', $item);
            }
        }
    }

    public function testDasaItemsReportByGroupApp()
    {
        $groupItems = $this->api->itemsReportByGroupApp('Routers_DASA', 'Interfaces', 1);
        $this->assertArrayHasKey('groups', $groupItems);
        $this->assertArrayHasKey('commonKeys', $groupItems);
    }

    /**
     * @SuppressWarnings("unused")
     */
    public function testDasaMapReduceItemHistory()
    {
        $item = array(
            "name" => "ifHCOutOctets",
            "lastvalue" => "0",
            "itemid" => "82217",
            "lastclock" => "1465827219",
            "prevvalue" => "0"
        );

        $compare = function ($item1, $item2) {
            return $item1->value - $item2->value;
        };

        $get = function ($item) {
            return $item->value;
        };

        $functions = array(
            "elapsed" => function ($history, $item, $since, $until) {
                return $until->getTimestamp() - $since->getTimestamp();
            },
            "top" => Util::avgTopPerc(0, $compare, $get),
            "avg_top5p" => Util::avgTopPerc(5, $compare, $get),
            "avg_top20p" => Util::avgTopPerc(20, $compare, $get),
            "avg_top50p" => Util::avgTopPerc(50, $compare, $get),
            "avg_top80p" => Util::avgTopPerc(80, $compare, $get)
        );

        $until = new \DateTime();
        $since = new \DateTime();
        $since->sub(new \DateInterval('PT1H'));

        $item = $this->api->mapReduceItemHistory($item, $since, $until, $functions);
        $this->assertGreaterThanOrEqual($item['avg_top5p'], $item['top']);
        $this->assertGreaterThanOrEqual($item['avg_top20p'], $item['avg_top5p']);
        $this->assertGreaterThanOrEqual($item['avg_top50p'], $item['avg_top20p']);
        $this->assertGreaterThanOrEqual($item['avg_top80p'], $item['avg_top50p']);
    }
}
