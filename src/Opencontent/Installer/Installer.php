<?php

namespace Opencontent\Installer;

use Exception;
use eZCharTransform;
use eZContentObjectTrashNode;
use eZDBInterface;
use eZSiteData;
use eZUser;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Psr\Log\LoggerInterface;


class Installer
{
    const INSTALLER_TYPE_DEFAULT = 'default';

    const INSTALLER_TYPE_MODULE = 'module';

    protected $db;

    protected $dataDir;

    protected $installerData = [];

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var IOTools
     */
    protected $ioTools;

    /**
     * @var StepInstallerFactory
     */
    protected $installerFactory;

    /**
     * @var bool
     */
    protected $dryRun = false;

    /**
     * @var bool
     */
    protected $isWaitForUser = false;

    protected $type;

    protected $currentVersion;

    protected $ignoreVersionCheck = false;

    protected $initLogMessage = '%s version %s';

    /**
     * OpenContentInstaller constructor.
     * @param eZDBInterface $db
     * @param $dataDir
     * @throws Exception
     */
    public function __construct(eZDBInterface $db, $dataDir, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new Logger();
        $this->installerVars = new InstallerVars();
        $this->installerVars->setLogger($this->logger);

        $this->db = $db;
        $this->dataDir = rtrim($dataDir, '/');
        $this->validateData();
        $this->installerData = Yaml::parse(file_get_contents($this->dataDir . '/installer.yml'));

        $this->type = $this->installerData['type'] ?? self::INSTALLER_TYPE_DEFAULT;

        if ($this->type !== self::INSTALLER_TYPE_DEFAULT && $this->type !== self::INSTALLER_TYPE_MODULE) {
            throw new Exception("Invalid installer type $this->type");
        }

        $this->logger->info(
            sprintf($this->initLogMessage, $this->installerData['name'], $this->installerData['version'])
        );

        $this->ioTools = new IOTools($this->dataDir, $this->installerVars);

        $this->installerFactory = new StepInstallerFactory(
            $this->logger,
            $this->installerVars,
            $this->ioTools,
            $this->db,
            $this->getCurrentVersion(),
            $this->isWaitForUser
        );
    }

    /**
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * @return InstallerVars
     */
    public function getInstallerVars(): InstallerVars
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
     * @return AbstractStepInstaller
     */
    public function installSchema(
        $cleanDb,
        $installBaseSchema,
        $installExtensionsSchema,
        $languageList,
        $cleanDataDirectory,
        $installDfsSchema
    ): AbstractStepInstaller {
        $installer = $this->installerFactory->factory(
            new Schema(
                $cleanDb,
                $installBaseSchema,
                $installExtensionsSchema,
                $languageList,
                $cleanDataDirectory,
                $installDfsSchema
            )
        );
        if ($this->dryRun) {
            $installer->dryRun();
        } else {
            $installer->install();
        }

        return $installer;
    }

    public function canInstallSchema(): bool
    {
        return $this->type == self::INSTALLER_TYPE_DEFAULT;
    }

    private function getSiteDataName(): string
    {
        if ($this->type == self::INSTALLER_TYPE_MODULE) {
            $identifier = eZCharTransform::instance()->transformByGroup($this->installerData['name'], 'identifier');

            return "ocinstaller_{$identifier}_version";
        }

        return 'ocinstaller_version';
    }

    public function getName()
    {
        return $this->installerData['name'];
    }

    public function getVariables()
    {
        $installerVariables = $this->installerData['variables'];
        $varParser = new InstallerVars();
        foreach ($installerVariables as $key => $value) {
            $installerVariables[$key]['parsed_value'] = $varParser->parseVarValue($value['value']);
        }
        return $installerVariables;
    }

    public function canUpdate()
    {
        if (\eZINI::instance('openpa.ini')->hasVariable('CreditsSettings', 'IsArchived')){
            return false;
        }
        return $this->getCurrentVersion() !== '0.0.0' && version_compare(
                $this->getCurrentVersion(),
                $this->installerData['version'],
                '<'
            );
    }

    public function needUpdate()
    {
        if ($this->ignoreVersionCheck) {
            return true;
        }
        $this->getLogger()->info("Installed version " . $this->getCurrentVersion());

        return version_compare($this->getCurrentVersion(), $this->installerData['version'], '<');
    }

    public function setIgnoreVersionCheck()
    {
        $this->ignoreVersionCheck = true;
    }

    private function getCurrentVersion()
    {
        if ($this->currentVersion === null) {
            $version = eZSiteData::fetchByName($this->getSiteDataName());
            if (!$version instanceof eZSiteData) {
                $this->currentVersion = '0.0.0';
            } else {
                $this->currentVersion = $version->attribute('value');
            }
        }

        return $this->currentVersion;
    }

    private function storeVersion()
    {
        $version = eZSiteData::fetchByName($this->getSiteDataName());
        if (!$version instanceof eZSiteData) {
            $version = new eZSiteData([
                'name' => $this->getSiteDataName(),
                'value' => $this->installerData['version'],
            ]);
        } else {
            $version->setAttribute('value', $this->installerData['version']);
        }
        $version->store();
        $this->storeDataDirForVersion();
    }

    private function storeDataDirForVersion()
    {
        $siteDataName = 'path_' . $this->getSiteDataName() . '@' . $this->installerData['version'];
        if (mb_strlen($siteDataName) > 60){
            $siteDataName = 'path_' . substr($this->getSiteDataName(),0, 45) . '@' . $this->installerData['version'];
        }
        $dataDir = realpath($this->dataDir);
        $version = eZSiteData::fetchByName($siteDataName);
        if (!$version instanceof eZSiteData) {
            $version = new eZSiteData([
                'name' => $siteDataName,
                'value' => $dataDir,
            ]);
        } else {
            $version->setAttribute('value', $dataDir);
        }
        $version->store();
    }

    /**
     * @param array $options
     * @return void
     * @throws Throwable
     */
    public function install(array $options = [])
    {
        if (eZContentObjectTrashNode::trashListCount(['Limitation' => []]) > 0) {
            $trashErrorMessage = "There are objects in trash: please empty trash before running installer";
            if ($this->isDryRun()){
                $this->getLogger()->error($trashErrorMessage);
            }else {
                throw new Exception($trashErrorMessage);
            }
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

        if (isset($options['vars']) && !empty($options['vars'])) {
            $keyValueList = explode(',', $options['vars']);
            foreach ($keyValueList as $keyValue) {
                [$key, $value] = explode(':', $keyValue);
                $this->logger->warning("Override variable $key = $value");
                $this->installerVars[trim($key)] = trim($value);
            }
        }

        /** @var Steps $stepsInstaller */
        $stepsInstaller = $this->installerFactory->factoryByType('steps');
        foreach ($steps as $index => $step) {
            $stepName = isset($step['identifier']) ? $step['type'] . ' ' . $step['identifier'] : $step['type'];
            if (!in_array($index, $onlySteps)) {
                $this->logger->debug("(skip) [$index] $stepName");
                continue;
            }
            $stepsInstaller->appendStep($step);
        }

        if ($this->dryRun) {
            $stepsInstaller->dryRun();
        } else {
            $stepsInstaller->install();
            $this->storeVersion();
        }
    }
    /**
     * @return void
     * @throws Exception
     */
    protected function validateData()
    {
        if (!file_exists($this->dataDir . '/installer.yml')) {
            throw new Exception("File $this->dataDir/installer.yml not found");
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

    public function isDryRun(): bool
    {
        return $this->dryRun === true;
    }

    public function setIsWaitForUser()
    {
        $this->logger->info("Wait mode enabled");
        $this->isWaitForUser = true;
        $this->installerFactory->setIsWaitForUser($this->isWaitForUser);
    }

    public static function parseDataDir(?string $dataDir = null): string
    {
        if (!$dataDir) {
            throw new Exception("Missing data argument");
        }

        if (is_dir($dataDir)) {
            return realpath($dataDir);
        }

        $prebuiltDataDirList = [
            'opencity' => 'vendor/opencity-labs/opencity-installer',
            'openagenda' => 'vendor/opencity-labs/openagenda-installer',
            'opencity-asl' => 'vendor/opencity-labs/opencity-asl-installer',
        ];

        $installerDirectory = $dataDir;
        $module = false;
        if (strpos($dataDir, '::')) {
            [$installerDirectory, $module] = explode('::', $dataDir, 2);
            if (empty($module)) {
                $module = '?';
            }
        }
        if (isset($prebuiltDataDirList[$installerDirectory]) && is_dir($prebuiltDataDirList[$installerDirectory])) {
            $installerDirectory = $prebuiltDataDirList[$installerDirectory];
            if ($module === '?') {
                throw new Exception(
                    "Missing module suffix in data argument. Available modules are: " . PHP_EOL . implode(
                        PHP_EOL,
                        self::findModules($installerDirectory)
                    )
                );
            }
            if ($module) {
                $installerDirectory .= '/modules/' . $module;
            }
        }

        if (!is_dir($installerDirectory)) {
            throw new Exception("Installer $dataDir not found");
        }

        return realpath($installerDirectory);
    }

    private static function findModules(string $baseDir)
    {
        $dirs = \eZDir::findSubitems($baseDir . '/modules', 'd');
        sort($dirs);

        return $dirs;
    }

    public function getCurrentVersions()
    {
        $list = [];
        $installerDirectory = $this->dataDir;
        if (strpos($installerDirectory, '/modules/')) {
            $installerDirectory = realpath(rtrim($installerDirectory . '/') . '/../..');
        }
        $list = [];
        $installerData = Yaml::parse(file_get_contents($installerDirectory . '/installer.yml'));
        $list['version'] = [
            'name' => $installerData['name'],
            'path' => '',
            'identifier' => '__main__',
            'current' => 'not-installed',
            'available' => $installerData['version'],
            'enable_gui' => $installerData['enable_gui'] ?? true,
            'type' => 'main',
            'data_dir' => $installerDirectory,
        ];
        $modules = self::findModules($installerDirectory);
        foreach ($modules as $module) {
            if (empty($module)) {
                continue;
            }
            if (file_exists($installerDirectory . '/modules/' . $module . '/installer.yml')) {
                $installerData = Yaml::parse(
                    file_get_contents($installerDirectory . '/modules/' . $module . '/installer.yml')
                );
                $moduleName = eZCharTransform::instance()->transformByGroup($installerData['name'], 'identifier');
                $list[$moduleName] = [
                    'name' => $installerData['name'],
                    'path' => $module,
                    'identifier' => $moduleName,
                    'current' => 'not-installed',
                    'available' => $installerData['version'],
                    'enable_gui' => $installerData['enable_gui'] ?? true,
                    'type' => 'module',
                    'data_dir' => $installerDirectory . '/modules/' . $module,
                ];
            }
        }
        $rows = eZSiteData::fetchObjectList(eZSiteData::definition(), null, ['name' => ['like', 'ocinstaller_%']]);
        foreach ($rows as $row) {
            $name = str_replace(['ocinstaller_', '_version'], '', $row->attribute('name'));
            if (isset($list[$name])) {
                $list[$name]['current'] = $row->attribute('value');
            }else{
                $list[$name] = [
                    'name' => '?',
                    'path' => '?',
                    'identifier' => $name,
                    'current' => $row->attribute('value'),
                    'available' => '?',
                    'enable_gui' => false,
                    'type' => '?',
                    'data_dir' => '?',
                ];
            }
        }
        krsort($list);
        return $list;
    }

    public static function getModuleVersion($moduleName)
    {
        $row = eZSiteData::fetchByName('ocinstaller_' . $moduleName . '_version');
        if ($row instanceof eZSiteData) {
            return $row->attribute('value');
        }

        return null;
    }
}