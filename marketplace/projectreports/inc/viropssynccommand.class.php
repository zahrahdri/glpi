<?php

/**
 * php bin/console plugins:projectreports:virops-sync
 * Reads the vSphere clusters from VirOPS (Forecast settings) for the forecast. Run it daily, e.g. from cron:
 *   15 6 * * *  docker exec -u www-data glpi-app php /var/www/html/glpi/bin/console plugins:projectreports:virops-sync
 */

use Glpi\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PluginProjectreportsViropssynccommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();
        $this->setName('plugins:projectreports:virops-sync');
        $this->setDescription('Read the vSphere clusters (capacity, use, 90-day growth) from VirOPS for the infrastructure forecast.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $n = PluginProjectreportsVirops::sync();
        } catch (Throwable $e) {
            $output->writeln('<error>VirOPS: ' . $e->getMessage() . '</error>');
            return 1;
        }
        $output->writeln(sprintf('<info>VirOPS: %d cluster(s) read.</info>', $n));
        return 0;
    }
}
