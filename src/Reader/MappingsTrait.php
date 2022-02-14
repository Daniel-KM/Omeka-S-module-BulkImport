<?php declare(strict_types=1);

namespace BulkImport\Reader;

trait MappingsTrait
{
    protected $mappingDirectory = '';

    protected $mappingExtension = '';

    protected function listMappings(array $subDirAndExtensions = []): array
    {
        $services = $this->getServiceLocator();
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        $files = [
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

            $this->mappingDirectory = dirname(__DIR__, 2) . '/data/mapping/' . $subDirectory;
            $this->mappingExtension = $extension;
            $files['module']['options'] = $this->getMappingFiles();

            $this->mappingDirectory = $basePath . '/mapping/' . $subDirectory;
            $userFiles = $this->getMappingFiles();
            foreach ($userFiles as $file) {
                $files['user']['options']['user: ' . $file] = $file;
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
}
