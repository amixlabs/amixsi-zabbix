<?php

namespace Amixsi\Zabbix\Schema;

class MaintenanceSchema
{
    public function validate($data)
    {
        $validator = new \JsonSchema\Validator;
        $validator->check($data, (object)[
            '$ref' => 'file://' . realpath(__DIR__ . '/maintenance.json')
        ]);
        return $validator;
    }
}
