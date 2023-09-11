<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Manager as ApiManager;

class MetaMapperConfigList extends AbstractPlugin
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $mappingDirectory = '';

    /**
     * @var string
     */
    protected $mappingExtension = '';

    public function __construct(ApiManager $api, string $basePath)
    {
        $this->api = $api;
        $this->basePath = $basePath;
    }

    public function invoke(): self
    {
        return $this;
    }

    public function listMappings(array $subDirAndExtensions = []): array
    {
        $files = [
            'mapping' => [
                'label' => 'Configured mappings', // @translate
                'options' => [],
            ],
            'user' => [
                'label' => 'User mapping files', // @translate
                'options' => [],
            ],
            'module' => [
                'label' => 'Module mapping files', // @translate
                'options' => [],
            ],
        ];

        foreach ($subDirAndExtensions as $subDirAndExtension) {
            $extension = reset($subDirAndExtension);
            $subDirectory = key($subDirAndExtension);

            if ($subDirectory === 'mapping' && $extension === true) {
                /** @var \BulkImport\Api\Representation\MappingRepresentation[] $mappings */
                $mappings = $this->api->search('bulk_mappings', ['sort_by' => 'label', 'sort_order' => 'asc'])->getContent();
                foreach ($mappings as $mapping) {
                    $files['mapping']['options']['mapping:' . $mapping->id()] = $mapping->label();
                }
                continue;
            }

            $this->mappingExtension = $extension;

            $this->mappingDirectory = dirname(__DIR__, 4) . '/data/mapping/' . $subDirectory;
            $mappingFiles = $this->getMappingFiles();
            foreach ($mappingFiles as $file) {
                $files['module']['options']['module:' . $subDirectory . '/' . $file] = $file;
            }

            $this->mappingDirectory = $this->basePath . '/mapping/' . $subDirectory;
            $mappingFiles = $this->getMappingFiles();
            foreach ($mappingFiles as $file) {
                $files['user']['options']['user:' . $subDirectory . '/' . $file] = $file;
            }
        }

        return $files;
    }

    /**
     * Get all files available to sideload.
     *
     * Adapted from the method in module FileSideload.
     * @see \FileSideload\Media\Ingester\Sideload::getMappingFiles();
     */
    protected function getMappingFiles(): array
    {
        $files = [];
        $dir = new \SplFileInfo($this->mappingDirectory);
        if ($dir->isDir()) {
            $lengthDir = strlen($this->mappingDirectory) + 1;
            $dir = new \RecursiveDirectoryIterator($this->mappingDirectory);
            // Prevent UnexpectedValueException "Permission denied" by excluding
            // directories that are not executable or readable.
            $dir = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
                if ($iterator->isDir() && (!$iterator->isExecutable() || !$iterator->isReadable())) {
                    return false;
                }
                return true;
            });
            $iterator = new \RecursiveIteratorIterator($dir);
            foreach ($iterator as $filepath => $file) {
                if ((!$this->mappingExtension || pathinfo($filepath, PATHINFO_EXTENSION) === $this->mappingExtension)
                        && $this->checkMappingFile($file)
                    ) {
                    // For security, don't display the full path to the user.
                    $relativePath = substr($filepath, $lengthDir);
                    // Use keys for quicker process on big directories.
                    $files[$relativePath] = null;
                }
            }
        }

        // Don't mix directories and files, but list directories first as usual.
        $alphabeticAndDirFirst = function ($a, $b) {
            if ($a === $b) {
                return 0;
            }
            $aInRoot = strpos($a, '/') === false;
            $bInRoot = strpos($b, '/') === false;
            if (($aInRoot && $bInRoot) || (!$aInRoot && !$bInRoot)) {
                return strcasecmp($a, $b);
            }
            return $bInRoot ? -1 : 1;
        };
        uksort($files, $alphabeticAndDirFirst);

        return array_combine(array_keys($files), array_keys($files));
    }

    /**
     * Check and get the realpath of a file.
     *
     * @todo Merge with main checkFile().
     */
    protected function checkMappingFile(\SplFileInfo $fileinfo): ?string
    {
        if ($this->mappingDirectory === false) {
            return null;
        }
        $realPath = $fileinfo->getRealPath();
        if ($realPath === false) {
            return null;
        }
        if (strpos($realPath, $this->mappingDirectory) !== 0) {
            return null;
        }
        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return null;
        }
        return $realPath;
    }

    public function getInternalBulkMappings(): array
    {
        static $internalBulkMappings;

        if ($internalBulkMappings === null) {
            $internalBulkMappings = $this->listMappings([
                ['base' => 'ini'],
                ['base' => 'xml'],
                ['json' => 'ini'],
                ['json' => 'xml'],
                ['xml' => 'ini'],
                ['xml' => 'xml'],
                ['xsl' => 'xsl'],
            ])['module']['options'];
        }

        return $internalBulkMappings;
    }

    /**
     * @todo Remove or factorize with MetaMapperConfig::prepareMappingContent().
     */
    public function getMappingFromFile(string $mappingName): ?string
    {
        $filepath = &$mappingName;

        if (mb_substr($filepath, 0, 5) === 'user:') {
            $filepath = $this->basePath . '/mapping/' . mb_substr($filepath, 5);
        } elseif (mb_substr($filepath, 0, 7) === 'module:') {
            $filepath = dirname(__DIR__, 4) . '/data/mapping/' . mb_substr($filepath, 7);
        } else {
            return null;
        }

        $path = realpath($filepath) ?: null;
        if (!$path || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return $content;
    }
}
