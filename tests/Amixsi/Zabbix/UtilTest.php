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

    public function testParseItemKeyValue2()
    {
        $strings = array(
            'ifAlias[0]',
            'ifSpeed[0]',
            'ifAlias[1]',
            'ifSpeed[1]'
        );
        $expected = array(
            array('0', 'ifAlias'),
            array('0', 'ifSpeed'),
            array('1', 'ifAlias'),
            array('1', 'ifSpeed')
        );

        $parsedStrings = array_map(function ($string) {
            return Util::parseItemKeyValue($string, 'key_');
        }, $strings);

        $this->assertEquals($expected, $parsedStrings);
    }

    public function testTopPerc()
    {
        $items = array(
            array('value' => 1),
            array('value' => 4),
            array('value' => 3),
            array('value' => 2),
            array('value' => 5)
        );

        $compare = function ($item1, $item2) {
            return $item1['value'] - $item2['value'];
        };

        $top100 = Util::topPerc($items, 100, $compare);
        $expectedTop100 = array(
            array('value' => 1),
            array('value' => 2),
            array('value' => 3),
            array('value' => 4),
            array('value' => 5)
        );
        $this->assertEquals($expectedTop100, $top100);

        $top50 = Util::topPerc($items, 50, $compare);
        $expectedTop50 = array(
            array('value' => 3),
            array('value' => 4),
            array('value' => 5)
        );
        $this->assertEquals($expectedTop50, $top50);

        $top40 = Util::topPerc($items, 40, $compare);
        $expectedTop40 = array(
            array('value' => 4),
            array('value' => 5)
        );
        $this->assertEquals($expectedTop40, $top40);

        $top10 = Util::topPerc($items, 10, $compare);
        $expectedTop10 = array(
            array('value' => 5)
        );
        $this->assertEquals($expectedTop10, $top10);
    }

    public function testArrayAverage()
    {
        $items = array(
            array('value' => 1),
            array('value' => 4),
            array('value' => 3),
            array('value' => 2),
            array('value' => 5)
        );
        $get = function ($item) {
            return $item['value'];
        };
        $avg = Util::arrayAverage($items, $get);
        $expectedAvg = 3;
        $this->assertEquals($expectedAvg, $avg);
    }
}
