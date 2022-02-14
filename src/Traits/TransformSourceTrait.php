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

    protected $transformSourceMappingConfig;

    protected $transformSourceParams;

    protected $transformSourceNormConfig;

    protected $transformSourceImportParams;

    protected function initTransformSource(string $mappingConfig, array $params)
    {
        $this->transformSource = $this->getServiceLocator()->get('ControllerPluginManager')->get('transformSource');
        $this->transformSourceMappingConfig = $mappingConfig;
        $this->transformSourceParams = $params;
        return $this
            ->transformSourcePrepareNormConfig()
            ->transformSourcePrepareImportParams();
    }

    private function transformSourcePrepareNormConfig()
    {
        $this->transformSourceNormConfig = [];

        $getConfig = function (?string $mappingConfig): ?string {
            if (empty($mappingConfig)) {
                return null;
            }
            if (mb_substr($mappingConfig, 0, 8) === 'mapping:') {
                $mappingId = (int) mb_substr($mappingConfig, 8);
                /** @var \BulkImport\Api\Representation\MappingRepresentation $mapping */
                $mapping = $this->getServiceLocator()->get('ControllerPluginManager')->get('api')
                    ->searchOne('bulk_mappings', ['id' => $mappingId])->getContent();
                return $mapping
                    ? $mapping->mapping()
                    : null;
            }
            $filename = basename($mappingConfig);
            if (empty($filename)) {
                return null;
            }
            $prefixes = [
                'user' => $this->basePath . '/mapping/',
                'module' => dirname(__DIR__, 2) . '/data/mapping/',
            ];
            $prefix = strtok($mappingConfig, ':');
            if (!isset($prefixes[$prefix])) {
                return null;
            }
            $file = mb_substr($mappingConfig, strlen($prefix) + 1);
            $filepath = $prefixes[$prefix] . $file;
            if (file_exists($filepath) && is_file($filepath) && is_readable($filepath) && filesize($filepath)) {
                return file_get_contents($filepath);
            }
            return null;
        };

        // The mapping file is in the config.
        if (!$this->transformSourceMappingConfig) {
            return $this;
        }
        $config = $getConfig($this->transformSourceMappingConfig);
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
                $mainConfig = $getConfig('base/' . $matches['master']);
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
        unset($vars['mapping_config'], $vars['filename']);
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
