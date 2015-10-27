<?php

namespace Amixsi\Zabbix\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Psr\Log\LogLevel;
use Amixsi\Zabbix\ZabbixApi;

class ApiInfoCommand extends Command
{
    protected function configure()
    {
        $apiUrl = 'http://10.4.170.246/zabbix/api_jsonrpc.php';
        $timeout = ini_get('default_socket_timeout');
        $this
            ->setName('zabbix:api-version')
            ->setDescription('Zabbix API Version')
            ->addOption(
                'timeout',
                't',
                InputOption::VALUE_REQUIRED,
                'Default socket timeout',
                $timeout
            )
            ->addOption(
                'api-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Zabbix URL API',
                $apiUrl
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $apiUrl = $input->getOption('api-url');

        $verbosityLevelMap = array(
            LogLevel::INFO => OutputInterface::VERBOSITY_VERBOSE
        );
        $logger = new ConsoleLogger($output, $verbosityLevelMap);

        $timeout = $input->getOption('timeout');
        ini_set('default_socket_timeout', $timeout);

        if ($output->isVerbose()) {
            $output->write('<info>Timeout: </info>');
            $output->writeln($timeout);
            $output->write('<info>ApiUrl:</info> ');
            $output->writeln($apiUrl);
        }
        $api = new ZabbixApi($apiUrl, '', '', $logger);

        $output->writeln($api->apiinfoVersion());
    }
}
