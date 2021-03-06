<?php
/**
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license   For full copyright and license information view LICENSE file distributed with this source code.
 */

namespace eZ\Launchpad\Command\Docker;

use eZ\Launchpad\Console\Application;
use eZ\Launchpad\Core\Client\Docker;
use eZ\Launchpad\Core\Command;
use eZ\Launchpad\Core\DockerCompose;
use eZ\Launchpad\Core\DockerSyncCommandTrait;
use eZ\Launchpad\Core\ProcessRunner;
use eZ\Launchpad\Core\ProjectStatusDumper;
use eZ\Launchpad\Core\ProjectWizard;
use eZ\Launchpad\Core\TaskExecutor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Class Initialize.
 */
class Initialize extends Command
{
    use DockerSyncCommandTrait;

    /**
     * @var ProjectStatusDumper
     */
    protected $projectStatusDumper;

    /**
     * Status constructor.
     *
     * @param ProjectStatusDumper $projectStatusDumper
     */
    public function __construct(ProjectStatusDumper $projectStatusDumper)
    {
        parent::__construct();
        $this->projectStatusDumper = $projectStatusDumper;
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->projectStatusDumper->setIo($this->io);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();
        $this->setName('docker:initialize')->setDescription('Initialize the project and all the services.');
        $this->setAliases(['docker:init', 'initialize', 'init']);
        $this->addArgument('repository', InputArgument::OPTIONAL, 'eZ Platform Repository', 'ezsystems/ezplatform');
        $this->addArgument('version', InputArgument::OPTIONAL, 'eZ Platform Version', '2.*');
        $this->addArgument('initialdata', InputArgument::OPTIONAL, 'eZ Platform Initial', 'clean');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fs          = new Filesystem();
        $application = $this->getApplication();
        /* @var Application $application */
        $output->writeln($application->getLogo());

        // Get the Payload docker-compose.yml
        $compose = new DockerCompose("{$this->getPayloadDir()}/dev/docker-compose.yml");
        $wizard  = new ProjectWizard($this->io, $this->projectConfiguration);

        // Ask the questions
        list(
            $networkName,
            $networkPort,
            $httpBasics,
            $selectedServices,
            $provisioningName,
            $composeFileName
            ) = $wizard(
            $compose
        );

        $compose->filterServices($selectedServices);

        // start the scafolding of the Payload
        $provisioningFolder = "{$this->projectPath}/{$provisioningName}";
        $fs->mkdir("{$provisioningFolder}/dev");
        $fs->mirror("{$this->getPayloadDir()}/dev", "{$provisioningFolder}/dev");
        $fs->chmod(
            [
                "{$provisioningFolder}/dev/nginx/entrypoint.bash",
                "{$provisioningFolder}/dev/engine/entrypoint.bash",
                "{$provisioningFolder}/dev/solr/entrypoint.bash",
            ],
            0755
        );

        // PHP.ini ADAPTATION
        $phpINIPath = "{$provisioningFolder}/dev/engine/php.ini";
        $conf       = <<<END
; redis configuration in dev 
session.save_handler = redis
session.save_path = "tcp://redis:6379"
END;
        $iniContent = file_get_contents($phpINIPath);
        $iniContent = str_replace(
            '##REDIS_CONFIG##',
            $compose->hasService('redis') ? $conf : '',
            $iniContent
        );

        $conf       = <<<END
; mailcatcher configuration in dev 
sendmail_path = /usr/bin/env catchmail --smtp-ip mailcatcher --smtp-port 1025 -f docker@localhost
END;
        $iniContent = str_replace(
            '##SENDMAIL_CONFIG##',
            $compose->hasService('mailcatcher') ? $conf : '',
            $iniContent
        );
        $fs->dumpFile($phpINIPath, $iniContent);
        unset($selectedServices);

        // Clean the Compose File
        $compose->removeUselessEnvironmentsVariables();

        // Get the Payload README.md
        $fs->copy("{$this->getPayloadDir()}/README.md", "{$provisioningFolder}/README.md");

        // create the local configurations
        $localConfigurations = [
            'provisioning.folder_name'   => $provisioningName,
            'docker.compose_filename'    => $composeFileName,
            'docker.network_name'        => $networkName,
            'docker.network_prefix_port' => $networkPort,
        ];

        foreach ($httpBasics as $name => $httpBasic) {
            list($host, $user, $pass)                                    = $httpBasic;
            $localConfigurations["composer.http_basic.{$name}.host"]     = $host;
            $localConfigurations["composer.http_basic.{$name}.login"]    = $user;
            $localConfigurations["composer.http_basic.{$name}.password"] = $pass;
        }

        $this->projectConfiguration->setMultiLocal($localConfigurations);

        // Create the docker Client
        $options      = [
            'compose-file'             => "{$provisioningFolder}/dev/{$composeFileName}",
            'network-name'             => $networkName,
            'network-prefix-port'      => $networkPort,
            'project-path'             => $this->projectPath,
            'provisioning-folder-name' => $provisioningName,
            'host-machine-mapping'     => $this->projectConfiguration->get('docker.host_machine_mapping'),
            'composer-cache-dir'       => $this->projectConfiguration->get('docker.host_composer_cache_dir'),
        ];
        $dockerClient = new Docker($options, new ProcessRunner());
        $this->dockerSyncClientConnect($dockerClient);
        $this->projectStatusDumper->setDockerClient($dockerClient);

        // do the real work
        $this->innerInitialize(
            $dockerClient,
            $compose,
            "{$provisioningFolder}/dev/{$composeFileName}",
            $input
        );

        // remove unused solr
        if (!$compose->hasService('solr')) {
            $fs->remove("{$provisioningFolder}/dev/solr");
        }
        // remove unused varnish
        if (!$compose->hasService('varnish')) {
            $fs->remove("{$provisioningFolder}/dev/varnish");
        }

        $this->projectConfiguration->setEnvironment('dev');
        $this->projectStatusDumper->dump('ncsi');
    }

    /**
     * @param Docker         $dockerClient
     * @param DockerCompose  $compose
     * @param string         $composeFilePath
     * @param InputInterface $input
     */
    protected function innerInitialize(
        Docker $dockerClient,
        DockerCompose $compose,
        $composeFilePath,
        InputInterface $input
    ) {
        $tempCompose = clone $compose;
        $tempCompose->cleanForInitialize();
        // dump the temporary DockerCompose.yml without the mount and env vars in the provisioning folder
        $tempCompose->dump($composeFilePath);
        unset($tempCompose);

        // Do the first pass to get eZ Platform and related files
        $dockerClient->build(['--no-cache']);
        $dockerClient->up(['-d']);

        $executor = new TaskExecutor($dockerClient, $this->projectConfiguration, $this->requiredRecipes);
        $executor->composerInstall();

        // Fix #7
        // if eZ EE is selected then the DB is not selected by the install process
        // we have to do it manually here
        $repository  = $input->getArgument('repository');
        $initialdata = $input->getArgument('initialdata');

        if ('clean' === $initialdata && false !== strpos($repository, 'ezplatform-ee')) {
            $initialdata = 'studio-clean';
        }

        $executor->eZInstall($input->getArgument('version'), $repository, $initialdata);
        if ($compose->hasService('solr')) {
            $executor->eZInstallSolr();
        }
        $compose->dump($composeFilePath);

        $dockerClient->up(['-d']);
        $executor->composerInstall();

        if ($compose->hasService('solr')) {
            $executor->createCore();
            $executor->indexSolr();
        }
    }
}
