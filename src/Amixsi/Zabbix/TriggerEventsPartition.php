<?php

namespace Amixsi\Zabbix;

use Psr\Log\LoggerInterface;
use Amixsi\Helper\DateRange;
use Doctrine\DBAL\Connection;

class TriggerEventsPartition
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

    public function getDownEvents($triggers, \DateTime $since, \DateTime $until, $priorities = null)
    {
        $conn = $this->conn;
        $this->cacheEvents($triggers, $since, $until, $priorities);
        return $conn->fetchAll(
            "
            select
                host,
                dt_alert,
                max(elapsed) as elapsed
            from alert
            where dt_alert between ? and ?
            and value = 1
            group by 1, 2
            order by 1, 3 desc
            ",
            array($since, $until),
            array('datetimetz', 'datetimetz')
        );
    }

    public function cacheEvents($refTriggers, \DateTime $since, \DateTime $until, $priorities = null)
    {
        $api = $this->api;
        $conn = $this->conn;
        $logger = $this->logger;
        $this->updateOrCreateSchema($conn);
        $dateRange = new DateRange($since, $until);
        $dates = $dateRange->getRange();
        $dayPartitions = array(
            array(
                'PT6H0M0S',
                'PT6H0M0S',
                'PT6H0M0S',
                'PT5H59M59S'
            ),
            array(
                'PT3H0M0S',
                'PT3H0M0S',
                'PT3H0M0S',
                'PT3H0M0S',
                'PT3H0M0S',
                'PT3H0M0S',
                'PT3H0M0S',
                'PT2H59M59S'
            ),
        );
        foreach ($dates as $date) {
            $date->setTime(0, 0, 0);
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
                        $dateSince = clone $date;
                        $dateUntil = clone $date;
                        $triggers = array();
                        foreach ($dayPartition as $interval) {
                            $dateUntil->add(new \DateInterval($interval));
                            $triggers2 = $api->eventsByTriggers(
                                $refTriggers,
                                $dateSince,
                                $dateUntil,
                                $priorities
                            );
                            $triggers = array_merge($triggers, $triggers2);
                            $logger->debug('Triggers count {count}', array(
                                'count' => count($triggers)
                            ));
                            $dateSince = clone $dateUntil;
                        }
                        $this->insertTriggers($conn, $triggers);
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
    }

    private function updateOrCreateSchema(Connection $conn)
    {
        $schemaManager = $conn->getSchemaManager();
        if (!$schemaManager->tablesExist('alert')) {
            $schema = new \Doctrine\DBAL\Schema\Schema();
            $alert = $schema->createTable('alert');
            $alert->addColumn('dt_alert', 'datetimetz', array('unsigned' => true));
            $alert->addColumn('dt_end', 'datetimetz', array('unsigned' => true));
            $alert->addColumn('triggerid', 'integer', array('unsigned' => true));
            $alert->addColumn('description', 'string');
            $alert->addColumn('hosts', 'string');
            $alert->addColumn('groups', 'string');
            $alert->addColumn('priority', 'integer', array('unsigned' => true));
            $alert->addColumn('value', 'integer', array('unsigned' => true));
            $alert->addColumn('count', 'integer', array('unsigned' => true));
            $alert->addColumn('elapsed', 'integer', array('unsigned' => true));
            $alert->addIndex(array('dt_alert', 'dt_end'));
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
        $begin = clone $ref;
        $begin->setTime(0, 0, 0);
        $end = clone $begin;
        $end->setTime(23, 59, 59);
        $stmt = $conn->executeQuery(
            '
            select 1
            where exists (
                select 1
                from alert
                where dt_alert between ? and ?
            )',
            array($begin, $end),
            array('datetimetz', 'datetimetz')
        );
        $stmt->execute();
        if ($stmt->fetch()) {
            return true;
        }
        return false;
    }

    private function insertTriggers(Connection $conn, $triggers)
    {
        $dateType = \Doctrine\DBAL\Types\Type::getType('datetimetz');
        $platform = $conn->getDatabasePlatform();
        foreach ($triggers as $trigger) {
            $hosts = array();
            foreach ($trigger->hosts as $host) {
                $hosts[] = $host->name;
            }
            $groups = array();
            foreach ($trigger->groups as $group) {
                $groups[] = $group->name;
            }
            foreach ($trigger->events as $event) {
                $clock = new \DateTime();
                $clock->setTimestamp($event->clock);
                $dt_alert = $dateType->convertToDatabaseValue($clock, $platform);
                $clock->setTimestamp($event->clock + $event->elapsed);
                $dt_end = $dateType->convertToDatabaseValue($clock, $platform);
                $conn->insert('alert', [
                    'dt_alert' => $dt_alert,
                    'dt_end' => $dt_end,
                    'triggerid' => $trigger->triggerid,
                    'description' => $trigger->description,
                    'hosts' => implode(',', $hosts),
                    'groups' => implode(',', $groups),
                    'priority' => $trigger->priority,
                    'value' => $event->value,
                    'count' => 1,
                    'elapsed' => $event->elapsed,
                ]);
            }
        }
        return true;
    }
}
