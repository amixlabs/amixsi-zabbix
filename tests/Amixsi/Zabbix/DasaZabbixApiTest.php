<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;

class DasaZabbixApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $apiUrl = 'http://10.253.14.129/zabbix/api_jsonrpc.php';
        $user = getenv('DASA_USER');
        $password = getenv('DASA_PASS');
        $this->api = new ZabbixApi($apiUrl, $user, $password);
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
}
