<?php declare(strict_types=1);

namespace BulkImport\Traits;

/**
 * Manage transformSource config, that may be inside multiple files.
 */
trait TransformSourceTrait
{
    /**
     * @var \BulkImport\Mvc\Controller\Plugin\TransformSource
     */
    protected $transformSource;

    protected $transformSourceMappingFile;

    protected $transformSourceParams;

    protected $transformSourceNormConfig;

    protected $transformSourceImportParams;

    protected function initTransformSource(string $mappingFile, array $params)
    {
        $this->transformSource = $this->getServiceLocator()->get('ControllerPluginManager')->get('transformSource');
        $this->transformSourceMappingFile = $mappingFile;
        $this->transformSourceParams = $params;
        return $this
            ->transformSourcePrepareNormConfig()
            ->transformSourcePrepareImportParams();
    }

    private function transformSourcePrepareNormConfig()
    {
        $this->transformSourceNormConfig = [];

        $getConfig = function (?string $file):?string {
            if (empty($file)) {
                return null;
            }
            $filename = basename($file);
            if (empty($filename)) {
                return null;
            }
            $prefixes = [
                $this->basePath . '/mapping/',
                dirname(__DIR__, 2) . '/data/mapping/',
                dirname(__DIR__, 2) . '/data/mapper/',
            ];
            // Remove extension: the filename may be basename or with ".ini".
            $filebase = substr($file, -4) === '.ini' ? substr($file, 0, -4) : $file;
            foreach ($prefixes as $prefix) {
                $filepath = $prefix . $filebase . '.ini';
                if (file_exists($filepath) && is_file($filepath) && is_readable($filepath) && filesize($filepath)) {
                    return file_get_contents($filepath);
                }
            }
            return null;
        };

        // The mapping file is in the config.
        if (!$this->transformSourceMappingFile) {
            return $this;
        }
        $config = $getConfig($this->transformSourceMappingFile);
        if (!$config) {
            return $this;
        }

        // Check if the config has a main config.
        $mainConfig = null;
        $isInfo = false;
        $matches = [];
        foreach ($this->transformSource->stringToList($config) as $line) {
            if ($line === '[info]') {
                $isInfo = true;
            } elseif (substr($line, 0, 1) === '[') {
                $isInfo = false;
            } elseif ($isInfo && preg_match('~^mapper\s*=\s*(?<master>[a-zA-Z][a-zA-Z0-9_-]*)*$~', $line, $matches)) {
                $mainConfig = $getConfig($matches['master']);
                break;
            }
        }

        $this->transformSourceNormConfig = $this->transformSource
            ->setConfigSections([
                'info' => 'raw',
                'params' => 'raw_or_pattern',
                'default' => 'mapping',
                'mapping' => 'mapping',
            ])
            ->setConfigs($mainConfig, $config)
            ->getNormalizedConfig();

        return $this;
    }

    private function transformSourcePrepareImportParams()
    {
        $this->transformSourceImportParams = [];

        if (empty($this->transformSourceNormConfig['params'])) {
            return $this;
        }

        // Prepare the list of variables and params.
        // "importParams" is different from the transformSource config: it
        // contains converted data.
        $vars = $this->transformSourceParams;
        unset($vars['mapping_file'], $vars['filename']);
        foreach (array_keys($this->transformSourceNormConfig['params']) as $from) {
            // TODO Clarify transform source: the two next lines are the same.
            // $value = $this->transformSource->setVariables($vars)->convertTargetToString($from, $to);
            $value = $this->transformSource->setVariables($vars)->convertToString('params', $from);
            $this->transformSourceImportParams[$from] = $value;
            $vars[$from] = $value;
        }

        return $this;
    }
}
