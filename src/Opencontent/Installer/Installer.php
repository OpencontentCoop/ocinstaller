<?php

namespace Opencontent\Installer;

use Exception;
use eZDBInterface;
use eZUser;
use Symfony\Component\Yaml\Yaml;

class Installer
{
    const INSTALLER_TYPE_DEFAULT = 'default';

    const INSTALLER_TYPE_MODULE = 'module';

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
     * @var bool
     */
    private $dryRun = false;

    private $type = false;

    private $currentVersion;

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

        $this->type = isset($this->installerData['type']) ? $this->installerData['type'] : self::INSTALLER_TYPE_DEFAULT;

        if ($this->type !== self::INSTALLER_TYPE_DEFAULT && $this->type !== self::INSTALLER_TYPE_MODULE) {
            throw new Exception("Invalid installer type {$this->type}");
        }

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

    /**
     * @return InstallerVars
     */
    public function getInstallerVars()
    {
        return $this->installerVars;
    }

    public function getType()
    {
        return $this->type;
    }

    /**
     * @param $cleanDb
     * @param $installBaseSchema
     * @param $installExtensionsSchema
     * @param $languageList
     * @param $cleanDataDirectory
     * @param $installDfsSchema
     * @return Schema|AbstractStepInstaller|InterfaceStepInstaller
     */
    public function installSchema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory, $installDfsSchema)
    {
        $installer = $this->installerFactory->factory(new Schema($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory, $installDfsSchema));
        if ($this->dryRun) {
            $installer->dryRun();
        } else {
            $installer->install();
        }

        return $installer;
    }

    public function canInstallSchema()
    {
        return $this->type == self::INSTALLER_TYPE_DEFAULT;
    }

    private function getSiteDataName()
    {
        if ($this->type == self::INSTALLER_TYPE_MODULE) {
            $identifier = \eZCharTransform::instance()->transformByGroup($this->installerData['name'], 'identifier');

            return "ocinstaller_{$identifier}_version";
        }

        return 'ocinstaller_version';
    }

    public function needUpdate()
    {
        $this->getLogger()->info("Installed version " . $this->getCurrentVersion());

        return version_compare($this->getCurrentVersion(), $this->installerData['version'], '<');
    }

    private function getCurrentVersion()
    {
        if ($this->currentVersion === null){
            $version = \eZSiteData::fetchByName($this->getSiteDataName());
            if (!$version instanceof \eZSiteData) {
                $this->currentVersion = '0.0.0';
            } else {
                $this->currentVersion = $version->attribute('value');
            }
        }

        return $this->currentVersion;
    }
    
    private function storeVersion()
    {
        $version = \eZSiteData::fetchByName($this->getSiteDataName());
        if (!$version instanceof \eZSiteData) {
            $version = \eZSiteData::create($this->getSiteDataName(), $this->installerData['version']);
        } else {
            $version->setAttribute('value', $this->installerData['version']);
        }
        $version->store();
    }

    public function install($options = array())
    {
        if (\eZContentObjectTrashNode::trashListCount() > 0) {
            throw new Exception("There are objects in trash: please empty trash before running installer");
        }

        $this->installerVars['current_version'] = $this->getCurrentVersion();
        $this->getLogger()->info("Update to version " . $this->installerData['version']);
        $onlyStep = $options['only-step'];

        $steps = $this->installerData['steps'];
        $onlySteps = array_keys($steps);

        if ($onlyStep !== null) {
            $onlySteps = explode(',', $onlyStep);
        }

        if (!$this->isDryRun()) {
            /** @var eZUser $adminUser */
            $adminUser = eZUser::fetchByName('admin');
            eZUser::setCurrentlyLoggedInUser($adminUser, $adminUser->id());
        }

        $this->loadDataVariables();

        foreach ($steps as $index => $step) {

            $stepName = isset($step['identifier']) ? $step['type'] . ' ' . $step['identifier'] : $step['type'];
            if (!in_array($index, $onlySteps)) {
                $this->logger->debug("(skip) [$index] $stepName");
                continue;
            }

            switch ($step['type']) {

                case 'tagtree':
                    $installer = new TagTree();
                    break;

                case 'state':
                    $installer = new State();
                    break;

                case 'section':
                    $installer = new Section();
                    break;

                case 'class':
                    $installer = new ContentClass();
                    break;

                case 'content':
                    $installer = new Content();
                    break;

                case 'role':
                    $installer = new Role();
                    break;

                case 'workflow':
                    $installer = new Workflow();
                    break;

                case 'contenttree':
                    $installer = new ContentTree();
                    break;

                case 'classextra':
                    $installer = new ContentClassExtra();
                    break;

                case 'openparecaptcha':
                    $installer = new OpenPARecaptcha();
                    break;

                case 'sql':
                case 'sql_copy_from_tsv':
                    $installer = new Sql();
                    break;

                default:
                    throw new Exception("Step type " . $step['type'] . ' not handled');
            }

            if ($installer instanceof InterfaceStepInstaller) {
                $installer = $this->installerFactory->factory($installer);
                $ignoreError = false;
                if (isset($step['ignore_error'])) {
                    $ignoreError = (bool)$step['ignore_error'];
                }
                if (isset($step['condition']) && $step['condition'] == '$is_install_from_scratch' && !$this->installerVars['is_install_from_scratch']) {
                    $ignoreError = true;
                }
                try {
                    $installer->setStep($step);
                    
                    $skip = false;

                    if (isset($installer->getStep()['current_version_lt'])){
                        $skip = version_compare($this->getCurrentVersion(), $installer->getStep()['current_version_lt'], 'lt') == false;
                        $this->logger->debug("[$index] $stepName skipped by version compare parameter");
                    }
                    if (isset($installer->getStep()['current_version_le'])){
                        $skip = version_compare($this->getCurrentVersion(), $installer->getStep()['current_version_le'], 'le') == false;
                        $this->logger->debug("[$index] $stepName skipped by version compare parameter");
                    }
                    if (isset($installer->getStep()['current_version_eq'])){
                        $skip = version_compare($this->getCurrentVersion(), $installer->getStep()['current_version_eq'], 'eq') == false;
                        $this->logger->debug("[$index] $stepName skipped by version compare parameter");
                    }
                    if (isset($installer->getStep()['current_version_ge'])){
                        $skip = version_compare($this->getCurrentVersion(), $installer->getStep()['current_version_ge'], 'ge') == false;
                        $this->logger->debug("[$index] $stepName skipped by version compare parameter");
                    }
                    if (isset($installer->getStep()['current_version_gt'])){
                        $skip = version_compare($this->getCurrentVersion(), $installer->getStep()['current_version_gt'], 'gt') == false;
                        $this->logger->debug("[$index] $stepName skipped by version compare parameter");
                    }
                    
                    if (isset($installer->getStep()['condition']) && (bool)$installer->getStep()['condition'] !== true) {
                        $this->logger->debug("[$index] $stepName skipped by condition parameter");
                        $skip = true;
                    }
                    
                    if (!$skip){
                        $this->logger->debug("[$index] $stepName");
                        if ($this->dryRun) {
                            $installer->dryRun();
                        } else {
                            $installer->install();
                        }
                    }
                } catch (\Throwable $e) {
                    if ($ignoreError) {
                        $this->getLogger()->error($e->getMessage());
                    } else {
                        throw $e;
                    }
                }
            }
        }

        $this->storeVersion();
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
            $this->logger->info("Load installer variables");

            foreach ($this->installerData['variables'] as $variable) {
                $this->installerVars[$variable['name']] = $variable['value'];
            }
        }
    }

    public function setDryRun()
    {
        $this->logger->info("Dry-run mode enabled");
        $this->dryRun = true;
    }

    public function isDryRun()
    {
        return $this->dryRun === true;
    }
}