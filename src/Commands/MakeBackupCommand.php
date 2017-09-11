<?php

namespace App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;

class MakeBackupCommand extends Command
{
    protected $fileSystem;

	protected $input;
    protected $output;
	protected $questionHelper;

	protected $sitePath;
	protected $backupFolder;
	protected $backupBasePath;

    protected function configure()
    {
        $this
        	->setName('backup')
        	->setDescription('Backup a website.')
        	->setHelp('This command allows you to backup a website.')

        	->addOption('user', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Shell users whose data should be exported alongside the backup')

        	->addOption('dbuser', null, InputOption::VALUE_REQUIRED, 'Database user', 'root')
        	->addOption('dbpass', null, InputOption::VALUE_REQUIRED, 'Database user password')
        	->addOption('dbname', null, InputOption::VALUE_REQUIRED, 'Database name to be used for export')
        	->addOption('dbtable', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Database tables to be exported (acts as a whitelist)')
        	->addOption('dbonly', null, InputOption::VALUE_NONE, 'Should only the database be exported?')

        	->addOption('dont-drop-db', null, InputOption::VALUE_NONE, 'Should the database be kept once removing the site after backup')

        	->addOption('destination', null, InputOption::VALUE_REQUIRED, 'Directory to export the backup to', getcwd())

        	->addOption('keep-previous', null, InputOption::VALUE_NONE, 'Should previous export data be kept')
        	->addOption('clean-after', null, InputOption::VALUE_NONE, 'Should backup data be removed after export')

        	->addOption('remove-after', null, InputOption::VALUE_NONE, 'Should the site be removed after export')

        	->addOption('vhost', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Virtual hosts to be exported')
        	->addOption('extra', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Extra files to be exported')

        	->addArgument('site', InputArgument::REQUIRED, 'Site data to be backed up')
        	->addArgument('name', InputArgument::OPTIONAL, 'Project name')
    	;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(0);

        $this->fileSystem = new FileSystem();

    	$this->input = $input;
    	$this->output = $output;
        $this->questionHelper = $this->getHelper('question');

        $unique = md5(microtime(true));

        $site = $input->getArgument('site');

        if (!file_exists($site) || !is_dir($site)) {
        	throw new \InvalidArgumentException(sprintf('Site %s does not exist or is not a directory.', $site));
        }

        $site = realpath($site);

        $this->sitePath = $sitePath = $site;
        $this->backupFolder = $backupFolder = sprintf('.backup-%s', $unique);
        $this->backupBasePath = $backupBasePath = implode(DIRECTORY_SEPARATOR, [$sitePath, $backupFolder]);

        if (!is_dir($backupBasePath)) {
        	mkdir($backupBasePath, 0755, true);
        }

        $destination = $input->getOption('destination');

        if ($destination && !is_dir($destination)) {
        	throw new \InvalidArgumentException(sprintf('Output %s does not exists or is not a directory.', $output));
        }

        $destination = realpath($destination);

        $this->exportDatabase();
        $this->exportVirtualHosts();
        $this->exportExtraFiles();
        $this->exportUsers();

        $name = $input->getArgument('name');

        if (!$name) {
            $name = basename($sitePath);
        }

        $keepPrevious = $input->getOption('keep-previous');
        if ($keepPrevious !== true) {
        	$this->removeBackupData();
        }

        $tarFile = $destination . DIRECTORY_SEPARATOR . $name . '.tar.gz';
        $tarBaseName = basename($tarFile);

        if (file_exists($tarFile)) {
            $question = new ConfirmationQuestion('Backup file exists. Overwrite?', false);

            if (!$this->questionHelper->ask($this->input, $this->output, $question)) {
                $this->output->writeln('Backup aborted.');
                return;
            } else {
                unlink($tarFile);
            }
        }

        $tar = new Process(sprintf('tar -czf "%s" "%s"', $tarFile, $sitePath));
        $tar->setTimeout(null);
        $tar->setIdleTimeout(null);
        $tar->mustRun();

        $output->writeln(sprintf('Exported project: %s (%s)', $name, $tarFile));

        if ($input->getOption('remove-after') === true) {
        	$this->recursiveRemoveDirectory($this->sitePath);
        } else {
	        $cleanAfter = $input->getOption('clean-after');
	        if ($cleanAfter === true) {
	        	$this->removeCurrentBackupData();
	        }
        }
    }

    protected function exportDatabase()
    {
    	$input = $this->input;
    	$output = $this->output;
    	$backupPath = $this->backupBasePath;

    	$database = $input->getOption('dbname');

    	if (!empty($database)) {
			$username = $input->getOption('dbuser');
			$password = $input->getOption('dbpass');

			if (empty($username) || empty($password)) {
				throw new \InvalidArgumentException(sprintf('Database username (%s) or password (%s) has not been specified.', $username, $password));
			}

    		$tables = $input->getOption('dbtable');

    		$output->writeln(sprintf('Dumping database: %s', $database));

    		$file = implode(DIRECTORY_SEPARATOR, [$backupPath, 'database.sql.gz']);

    		if (empty($tables)) {
    			$dump = new Process(sprintf('mysqldump --skip-extended-insert -u %s -p%s %s | gzip > %s', $username, $password, $database, $file));
    		} else {
    			$dump = new Process(sprintf('mysqldump --skip-extended-insert -u %s -p%s %s %s | gzip > %s', $username, $password, $database, implode(' ', $tables), $file));
    		}

            $dump->setTimeout(null);
            $dump->setIdleTimeout(null);
    		$dump->mustRun();
			$output->writeln(sprintf('Exported database to: %s', $file));

			if ($input->getOption('dbonly') === true) {
                $output->writeln('Requested only database export, exiting.');
				exit;
			}

			if ($input->getOption('remove-after') === true && $input->getOption('dont-drop-db') !== true) {
				if (empty($tables)) {
					$drop = new Process(sprintf('mysql -u %s -p%s -e "DROP DATABASE \`%s\`;"', $username, $password, $database));
				} else {
					$dropTables = array_map(function ($table) {
						return 'DROP TABLE \`' . $table . '\`;';
					}, $tables);
					$drop = new Process(sprintf('mysql -u %s -p%s -e "USE \`%s\`; %s"', $username, $password, $database, implode(' ', $dropTables)));
				}

                $drop->setTimeout(null);
                $drop->setIdleTimeout(null);
				$drop->mustRun();
			}
    	}
    }

    protected function exportVirtualHosts()
    {
    	$input = $this->input;
    	$output = $this->output;
    	$backupPath = $this->backupBasePath;

    	foreach ($input->getOption('vhost') as $vhost) {
            if (!$rvhost = realpath($vhost)) {
                throw new \InvalidArgumentException(sprintf('Virtual host (%s) could not be resolved.', $vhost));
            }

            $vhost = $rvhost;

    		$vhostInfo = pathinfo($vhost);

    		$vhostBasePath = implode(DIRECTORY_SEPARATOR, [$backupPath, 'vhosts']);

            if (!is_dir($vhostBasePath)) {
                mkdir($vhostBasePath, 0755, true);
            }

    		if (!copy($vhost, $destination = implode(DIRECTORY_SEPARATOR, [$vhostBasePath, $vhostInfo['basename']]))) {
                throw new \RuntimeException(sprintf('Failed to copy virtual host (%s) to backup destination (%s).', $vhost, $destination));
            }
    	}
    }

    protected function exportExtraFiles()
    {
    	$input = $this->input;
    	$output = $this->output;
    	$backupPath = $this->backupBasePath;

        $extraBasePath = implode(DIRECTORY_SEPARATOR, [$backupPath, 'extras']);

        foreach ($input->getOption('extra') as $extra) {
            if (!$rextra = realpath($extra)) {
                throw new \InvalidArgumentException(sprintf('Extra (%s) could not be resolved.', $extra));
            }

            $extra = $rextra;

            $extraInfo = pathinfo($extra);

            if (!is_dir($extraBasePath)) {
                mkdir($extraBasePath, 0755, true);
            }

            $extraPath = $extraInfo['dirname'];

            if (is_dir($extra)) {
                $extraPath .= '/' . $extraInfo['basename'];
            }

            $this->copy($extra, $extraBasePath . $extraPath, 0755);
        }
    }

    protected function exportUsers()
    {
    	$input = $this->input;
    	$output = $this->output;
    	$backupPath = $this->backupBasePath;

        foreach ($input->getOption('user') as $user) {
            $userinfo = posix_getpwnam($user);

            if ($userinfo === false) {
                throw new \InvalidArgumentException(sprintf('User with username (%s) not found.', $user));
            }

            $userBasePath = implode(DIRECTORY_SEPARATOR, [$backupPath, 'users']);

            if (!is_dir($userBasePath)) {
                mkdir($userBasePath, 0755, true);
            }

            $crontab = implode(DIRECTORY_SEPARATOR, [$userBasePath, sprintf('%s.crontab', $user)]);

            $cronHandle = fopen($crontab, 'a');

            $cronProcess = new Process(sprintf('crontab -u %s', $user));
            $cronProcess->setTimeout(null);
            $cronProcess->setIdleTimeout(null);
            $cronProcess->mustRun(function ($type, $buffer) use ($cronHandle) {
            	if (Process::OUT === $type) {
            		fwrite($cronHandle, $buffer);
            	}
            });

            fclose($cronHandle);

            $sshKeys = $userinfo['dir'] . '/.ssh/authorized_keys';
            $backupKeys = implode(DIRECTORY_SEPARATOR, [$userBasePath, sprintf('%s.authorized_keys', $user)]);

            if (!copy($sshKeys, $backupKeys)) {
            	throw new \RuntimeException(sprintf('Could not copy authorized_keys of user (%s) to %s.', $user, $backupKeys));
            }
        }
    }

    protected function removeBackupData()
    {
    	$pattern = $this->sitePath . DIRECTORY_SEPARATOR . '.backup-*';
    	$backups = glob($pattern, GLOB_ONLYDIR);

    	foreach ($backups as $backup) {
    		if ($backup !== $this->backupBasePath) {
    			$this->recursiveRemoveDirectory($backup);
    		}
    	}
    }

    protected function removeCurrentBackupData()
    {
    	$this->recursiveRemoveDirectory($this->backupBasePath);
    }

    protected function recursiveRemoveDirectory($directory)
    {
    	$contents = glob($pattern = $directory . '/{,.}[!.,!..]*', GLOB_BRACE);

        foreach($contents as $file) {
	        if (is_dir($file)) {
	            $this->recursiveRemoveDirectory($file);
	        } else {
	            unlink($file);
	        }
	    }

	    rmdir($directory);
    }

    /**
     * Copy a file, or recursively copy a folder and its contents
     * @author      Aidan Lister <aidan@php.net>
     * @version     1.0.1
     * @link        http://aidanlister.com/2004/04/recursively-copying-directories-in-php/
     * @param       string   $source    Source path
     * @param       string   $target      Destination path
     * @param       int      $permissions New folder creation permissions
     * @return      bool     Returns true on success, false on failure
     */
    protected function copy($source, $target, $permissions = 0755)
    {
        if (file_exists($target))
        {
            $this->fileSystem->remove($target);
        }

        $this->fileSystem->mkdir($target, $permissions);

        $directoryIterator = new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item)
        {
            if ($item->isDir())
            {
                $this->fileSystem->mkdir($target . DIRECTORY_SEPARATOR . $iterator->getSubPathName(), $permissions);
            }
            else
            {
                $this->fileSystem->copy($item, $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
            }
        }
    }
}
