<?php

namespace Opencontent\Installer;

use Symfony\Component\Yaml\Yaml;
use eZDBInterface;
use Exception;
use eZUser;

class Installer
{
    protected $db;

    protected $dataDir;

    protected $installerData = array();

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var IOTools
     */
    private $ioTools;

    /**
     * @var StepInstallerFactory
     */
    private $installerFactory;


    /**
     * OpenContentInstaller constructor.
     * @param eZDBInterface $db
     * @param $dataDir
     * @throws Exception
     */
    public function __construct(eZDBInterface $db, $dataDir)
    {
        $this->logger = new Logger();
        $this->installerVars = new InstallerVars();
        $this->installerVars->setLogger($this->logger);

        $this->db = $db;
        $this->dataDir = rtrim($dataDir, '/');
        $this->validateData();
        $this->installerData = Yaml::parse(file_get_contents($this->dataDir . '/installer.yml'));

        $this->log("Install " . $this->installerData['name'] . ' version ' . $this->installerData['version']);

        $this->ioTools = new IOTools($this->dataDir, $this->installerVars);

        $this->installerFactory = new StepInstallerFactory(
            $this->logger,
            $this->installerVars,
            $this->ioTools,
            $this->db
        );
    }

    public function installSchema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory)
    {
        $installer = $this->installerFactory->factory(new Schema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory));
        $installer->install();
    }

    protected function validateData()
    {
        if (!file_exists($this->dataDir . '/installer.yml')) {
            throw new Exception("File {$this->dataDir}/installer.yml not found");
        }
    }

    public function install()
    {
        /** @var eZUser $adminUser */
        $adminUser = eZUser::fetchByName('admin');
        eZUser::setCurrentlyLoggedInUser($adminUser, $adminUser->id());

        $this->loadDataVariables();

        foreach ($this->installerData['steps'] as $step) {
            switch ($step['type']) {

                case 'tagtree':
                    $this->installTagTree($step);
                    break;

                case 'state':
                    $this->installState($step);
                    break;

                case 'section':
                    $this->installSection($step);
                    break;

                case 'class':
                    $this->installClass($step);
                    break;

                case 'content':
                    $this->installContent($step);
                    break;

                case 'role':
                    $this->installRole($step);
                    break;

                case 'workflow':
                    $this->installWorkflow($step);
                    break;

                default:
                    throw new Exception("Step type " . $step['type'] . ' not handled');
            }
        }
    }

    protected function loadDataVariables()
    {
        if (isset($this->installerData['variables'])) {
            $this->logger->log("Load installer vars:");

            foreach ($this->installerData['variables'] as $variable) {
                $this->installerVars[$variable['name']] = $variable['value'];
            }
        }

        $stepsData = $this->installerVars->filter(json_encode($this->installerData['steps']));
        $this->installerData['steps'] = json_decode($stepsData, true);
    }

    protected function installTagTree($step)
    {
        $installer = $this->installerFactory->factory(new TagTree($step));
        $installer->install();
    }

    protected function installState($step)
    {
        $installer = $this->installerFactory->factory(new State($step));
        $installer->install();
    }

    protected function installSection($step)
    {
        $installer = $this->installerFactory->factory(new Section($step));
        $installer->install();
    }

    protected function installClass($step)
    {
        $installer = $this->installerFactory->factory(new ContentClass($step));
        $installer->install();
    }

    protected function installContent($step)
    {
        $installer = $this->installerFactory->factory(new Content($step));
        $installer->install();
    }

    protected function installRole($step)
    {
        $installer = $this->installerFactory->factory(new Role($step));
        $installer->install();
    }

    protected function installWorkflow($step)
    {
        $installer = $this->installerFactory->factory(new Workflow($step));
        $installer->install();
    }
}