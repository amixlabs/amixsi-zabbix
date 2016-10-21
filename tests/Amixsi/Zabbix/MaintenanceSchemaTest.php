<?php

namespace Amixsi\Zabbix\Schema;

class MaintenanceSchemaTest extends \PHPUnit_Framework_TestCase
{
    public function testMaintenanceSchema()
    {
        $data = (object)[
            'maintenanceid' => 'b123',
            'active_since' => 1.23
        ];

        $schema = new MaintenanceSchema();
        $validator = $schema->validate($data);
        $this->assertFalse($validator->isValid());
        $this->assertCount(1, $validator->getErrors());
        foreach ($validator->getErrors() as $error) {
            $this->assertArrayHasKey('message', $error);
            $this->assertArrayHasKey('constraint', $error);
        }
    }
}
