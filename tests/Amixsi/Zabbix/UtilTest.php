<?php

namespace Amixsi\Zabbix;

class UtilTest extends \PHPUnit_Framework_TestCase
{
    public function testParseItemKeyValue()
    {
        $strings = array(
            '000000 - capacity',
            '000000 capacity',
            '000001 - capacity',
            '000001 capacity'
        );
        $expected = array(
            array(0, 'capacity'),
            array(0, 'capacity'),
            array(1, 'capacity'),
            array(1, 'capacity')
        );

        $parsedStrings = array_map(array('Amixsi\Zabbix\Util', 'parseItemKeyValue'), $strings);

        $this->assertEquals($expected, $parsedStrings);
    }
}
