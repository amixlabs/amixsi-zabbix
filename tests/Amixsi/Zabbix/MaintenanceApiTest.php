<?php

namespace Amixsi\Zabbix;

use Amixsi\Helper\ZabbixPriority;

class MaintenanceApiTest extends \PHPUnit_Framework_TestCase
{
    protected $api;

    protected function setUp()
    {
        $apiUrl = 'http://10.4.170.246/zabbix/api_jsonrpc.php';
        $user = 'amix.api.maintenance';
        $password = getenv('AMIX_API_MAINTENANCE');
        $this->api = new ZabbixApi($apiUrl, $user, $password);
    }

    public function setupRemoveMaintenanceItemTest()
    {
        $api = $this->api;
        $list = $api->maintenanceList();
        $list = array_filter($list, function ($item) {
            return $item->name == 'Teste via API';
        });
        if (count($list) == 1) {
            $ids = array_map(function ($item) {
                return $item->maintenanceid;
            }, $list);
            $api->maintenanceDelete($ids);
        }
    }

    public function testListMaintenance()
    {
        $api = $this->api;
        $list = $api->maintenanceList();
        $this->assertGreaterThan(0, count($list));
        $item = $list[0];
        //var_dump($item);
        $this->assertObjectHasAttribute('maintenanceid', $item);
        $this->assertObjectHasAttribute('name', $item);
        $this->assertObjectHasAttribute('maintenance_type', $item);
        $this->assertObjectHasAttribute('description', $item);
        $this->assertObjectHasAttribute('active_since', $item);
        $this->assertObjectHasAttribute('active_till', $item);
        $this->assertObjectHasAttribute('groups', $item);
        $this->assertObjectHasAttribute('hosts', $item);
        $this->assertObjectHasAttribute('timeperiods', $item);
        $this->assertInternalType('array', $item->groups);
        $this->assertInternalType('array', $item->hosts);
        $this->assertInternalType('array', $item->timeperiods);
    }

    public function testListMaintenanceWithFilter()
    {
        $api = $this->api;
        $list = $api->maintenanceList(array('maintenanceid' => 33));
        $this->assertEquals(1, count($list));
        $item = $list[0];
        $this->assertObjectHasAttribute('maintenanceid', $item);
        $this->assertObjectHasAttribute('name', $item);
        $this->assertObjectHasAttribute('maintenance_type', $item);
        $this->assertObjectHasAttribute('description', $item);
        $this->assertObjectHasAttribute('active_since', $item);
        $this->assertObjectHasAttribute('active_till', $item);
        $this->assertObjectHasAttribute('groups', $item);
        $this->assertObjectHasAttribute('hosts', $item);
        $this->assertObjectHasAttribute('timeperiods', $item);
        $this->assertInternalType('array', $item->groups);
        $this->assertInternalType('array', $item->hosts);
        $this->assertInternalType('array', $item->timeperiods);
    }

    public function testCreateMaintenance()
    {
        $this->setupRemoveMaintenanceItemTest();
        $api = $this->api;
        $hosts = $api->hostGet(array(
            'filter' => array('name' => 'PRD-ZABBIX-FK')
        ));
        $groups = $api->hostgroupGet(array(
            'filter' => array('name' => 'PRD-ZABBIX-FK')
        ));
        $hostids = array_map(function ($host) {
            return $host->hostid;
        }, $hosts);
        $groupids = array_map(function ($group) {
            return $group->groupid;
        }, $groups);
        $item = $api->maintenanceCreate(array(
            'name' => 'Teste via API',
            'active_since' => '1358844540',
            'active_till' => '1390466940',
            'hostids' => $hostids,
            'groupids' => $groupids,
            'timeperiods' => array(
                array(
                    'timeperiod_type' => 3,
                    'every' => 1,
                    'dayofweek' => 64,
                    'start_time' => '64800',
                    'period' => '3600'
                )
            )
        ));
        $this->assertObjectHasAttribute('maintenanceids', $item);
    }

    /**
     * @depends testCreateMaintenance
     */
    public function testUpdateMaintenance()
    {
        $api = $this->api;
        $list = $api->maintenanceList(array(
            'name' => 'Teste via API'
        ));
        $item = current($list);
        $id = $item->maintenanceid;
        $active_till = new \DateTime('tomorrow');
        $item->active_till = (string)$active_till->getTimestamp();
        $item->groups = array();
        $itemUpdated = $api->maintenanceUpdate($item);
        $list = $api->maintenanceList(array(
            'maintenanceid' => $id
        ));
        foreach ($item->timeperiods as $timeperiod) {
            unset($timeperiod->timeperiodid);
        }
        $expectedJson = json_encode($item, JSON_PRETTY_PRINT);
        $item = current($list);
        foreach ($item->timeperiods as $timeperiod) {
            unset($timeperiod->timeperiodid);
        }
        $json = json_encode($item, JSON_PRETTY_PRINT);
        $this->assertObjectHasAttribute('maintenanceids', $itemUpdated);
        $this->assertContains($id, $itemUpdated->maintenanceids);
        $this->assertEquals($expectedJson, $json);
    }

    /**
     * @depends testCreateMaintenance
     */
    public function testUpdateMaintenanceArray()
    {
        $api = $this->api;
        $list = $api->maintenanceList();
        $list = array_filter($list, function ($item) {
            return $item->name == 'Teste via API';
        });
        $item = current($list);
        $item = json_decode(json_encode($item), true);
        $active_till = new \DateTime('tomorrow');
        $item['active_till'] = $active_till->getTimestamp();
        $itemUpdated = $api->maintenanceUpdate($item);
        $this->assertObjectHasAttribute('maintenanceids', $itemUpdated);
    }
}
