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

        $this->logger->info("Install " . $this->installerData['name'] . ' version ' . $this->installerData['version']);

        $this->ioTools = new IOTools($this->dataDir, $this->installerVars);

        $this->installerFactory = new StepInstallerFactory(
            $this->logger,
            $this->installerVars,
            $this->ioTools,
            $this->db
        );
    }

    /**
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    public function installSchema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory)
    {
        $installer = $this->installerFactory->factory(new Schema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory));
        $installer->install();
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
                    $installer = new TagTree($step);
                    break;

                case 'state':
                    $installer = new State($step);
                    break;

                case 'section':
                    $installer = new Section($step);
                    break;

                case 'class':
                    $installer = new ContentClass($step);
                    break;

                case 'content':
                    $installer = new Content($step);
                    break;

                case 'role':
                    $installer = new Role($step);
                    break;

                case 'workflow':
                    $installer = new Workflow($step);
                    break;

                default:
                    throw new Exception("Step type " . $step['type'] . ' not handled');
            }

            if ($installer instanceof InterfaceStepInstaller) {
                $installer = $this->installerFactory->factory($installer);
                $installer->install();
            }
        }
    }

    protected function validateData()
    {
        if (!file_exists($this->dataDir . '/installer.yml')) {
            throw new Exception("File {$this->dataDir}/installer.yml not found");
        }
    }

    protected function loadDataVariables()
    {
        if (isset($this->installerData['variables'])) {
            $this->logger->info("Load vars");

            foreach ($this->installerData['variables'] as $variable) {
                $this->installerVars[$variable['name']] = $variable['value'];
            }
        }

        $stepsData = $this->installerVars->filter(json_encode($this->installerData['steps']));
        $this->installerData['steps'] = json_decode($stepsData, true);
    }
}