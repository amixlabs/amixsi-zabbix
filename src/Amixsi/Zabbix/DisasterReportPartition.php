<?php

namespace Amixsi\Zabbix;

use Psr\Log\LoggerInterface;
use Amixsi\Helper\DateRange;
use Doctrine\DBAL\Connection;

class DisasterReportPartition
{
    private $api = null;
    private $conn = null;
    private $logger = null;

    public function __construct(ZabbixApi $api, Connection $conn, LoggerInterface $logger = null)
    {
        $this->setAPI($api);
        $this->setConnection($conn);
        if ($logger != null) {
            $this->setLogger($logger);
        }
    }

    public function setAPI(ZabbixApi $api = null)
    {
        $this->api = $api;
    }

    public function setConnection(Connection $conn = null)
    {
        $this->conn = $conn;
    }

    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function get(\DateTime $since, \DateTime $until, $priorities, $options)
    {
        $api = $this->api;
        $conn = $this->conn;
        $logger = $this->logger;
        $this->updateOrCreateSchema($conn);
        $dateRange = new DateRange($since, $until);
        $dates = $dateRange->getRange();
        $dayPartitions = array(
            array(
                'PT23H59M59S'
            ),
            array(
                'PT6H0M0S',
                'PT6H0M0S',
                'PT6H0M0S',
                'PT5H59M59S'
            ),
        );
        foreach ($dates as $date) {
            if (!$this->dateExists($conn, $date)) {
                $exception = null;
                foreach ($dayPartitions as $dayPartition) {
                    try {
                        if ($exception) {
                            if ($logger) {
                                $logger->warning('Try again...');
                            }
                            $exception = null;
                        }
                        $date_since = clone $date;
                        $date_until = clone $date;
                        $triggers = array();
                        foreach ($dayPartition as $interval) {
                            $date_until->add(new \DateInterval($interval));
                            $triggers2 = $api->disasterReportGet2($date_since, $date_until, $priorities, $options);
                            $triggers = array_merge($triggers, $triggers2);
                            $logger->debug('Triggers count {count}', array(
                                'count' => count($triggers)
                            ));
                            $date_since = clone $date_until;
                        }
                        $this->insertTriggers($conn, $triggers, $date);
                        break;
                    } catch (\ZabbixApi\Exception $exp) {
                        $exception = $exp;
                    }
                }
                if ($exception) {
                    throw $exception;
                }
            }
        }
        $triggers = $this->getTriggers($conn, $since, $until);
        $options2 = array('withHost' => false);
        if (isset($options['withHost'])) {
            $options2['withHost'] = $options['withHost'];
        }
        $triggersGrouped = $this->groupTriggers($triggers, $options2);
        return $triggersGrouped;
    }

    private function groupTriggers($triggers, $options)
    {
        $group = array();
        $withHost = isset($options['withHost']) && $options['withHost'];
        foreach ($triggers as $trigger) {
            if ($withHost) {
                $key = $trigger['description'] . '|' . $trigger['host'];
            } else {
                $key = $trigger['description'];
            }
            $dt_alert = $trigger['dt_alert'];
            $count = $trigger['count'];
            if (isset($group[$key])) {
                $dates = $group[$key]['dates'];
                if (isset($dates[$dt_alert])) {
                    $group[$key]['dates'][$dt_alert]['count'] += $count;
                } else {
                    $group[$key]['dates'][$dt_alert] = array(
                        'dt_alert' => new \DateTime($dt_alert),
                        'count' => $count
                    );
                }
                $group[$key]['count'] += $count;
            } else {
                $group[$key] = array(
                    'description' => $trigger['description'],
                    'host' => $trigger['host'],
                    'dates' => array(
                        $dt_alert => array(
                            'dt_alert' => new \DateTime($dt_alert),
                            'count' => $count
                        )
                    ),
                    'count' => $count
                );
            }
        }
        return array_values($group);
    }

    private function updateOrCreateSchema(Connection $conn)
    {
        $schemaManager = $conn->getSchemaManager();
        if (!$schemaManager->tablesExist('alert')) {
            $schema = new \Doctrine\DBAL\Schema\Schema();
            $alert = $schema->createTable('alert');
            $alert->addColumn('dt_alert', 'date', array('unsigned' => true));
            $alert->addColumn('triggerid', 'integer', array('unsigned' => true));
            $alert->addColumn('description', 'string');
            $alert->addColumn('host', 'string');
            $alert->addColumn('priority', 'integer', array('unsigned' => true));
            $alert->addColumn('value', 'integer', array('unsigned' => true));
            $alert->addColumn('count', 'integer', array('unsigned' => true));
            $alert->addColumn('elapsed', 'integer', array('unsigned' => true));
            $alert->addIndex(array('dt_alert'));
            $sqls = $schema->toSql($conn->getDatabasePlatform());
            foreach ($sqls as $sql) {
                $conn->query($sql);
            }
            return true;
        }
        return false;
    }

    private function dateExists(Connection $conn, \DateTime $ref)
    {
        $dateType = \Doctrine\DBAL\Types\Type::getType('date');
        $dt_alert = $dateType->convertToDatabaseValue($ref, $conn->getDatabasePlatform());
        $stmt = $conn->executeQuery('
            select 1
            where exists (
                select 1
                from alert
                where dt_alert = ?
            )
        ', array($dt_alert));
        $stmt->execute();
        if ($stmt->fetch()) {
            return true;
        }
        return false;
    }

    private function insertTriggers(Connection $conn, $triggers, \DateTime $ref)
    {
        $dateType = \Doctrine\DBAL\Types\Type::getType('date');
        $dt_alert = $dateType->convertToDatabaseValue($ref, $conn->getDatabasePlatform());
        foreach ($triggers as $trigger) {
            foreach ($trigger->events as $event) {
                $conn->insert('alert', [
                    'dt_alert' => $dt_alert,
                    'triggerid' => $trigger->triggerid,
                    'description' => $trigger->description,
                    'host' => $trigger->host,
                    'priority' => $trigger->priority,
                    'value' => $event->value,
                    'count' => 1,
                    'elapsed' => $event->elapsed,
                ]);
            }
        }
        return true;
    }

    private function getTriggers(Connection $conn, \DateTime $since, \DateTime $until)
    {
        return $conn->fetchAll(
            "
            select
                description,
                host,
                strftime('%Y-%m-01', dt_alert) as dt_alert,
                sum(count) as count
            from alert
            where dt_alert between ? and ?
            and value = 1
            group by 1, 2, 3
            order by 1, 2, 3
            ",
            array($since, $until),
            array('date', 'date')
        );
    }
}
