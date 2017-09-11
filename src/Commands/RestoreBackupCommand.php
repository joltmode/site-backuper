<?php

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RestoreBackupCommand extends Command
{
    protected function configure()
    {
        $this
        	->setName('restore')
        	->setDescription('Restore a website.')
        	->setHelp('This command allows you to restore a website.')
    	;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(__METHOD__);
    }
}