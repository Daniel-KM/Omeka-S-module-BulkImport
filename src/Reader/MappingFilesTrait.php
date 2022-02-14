<?php declare(strict_types=1);

namespace BulkImport\Reader;

/**
 * This file is currently used with forms JsonReader and XmlReader.
 */
trait MappingFilesTrait
{
    protected $directory = '';

    protected $extension = '';

    protected function listFiles($subDirectory, $extension): array
    {
        $services = $this->getServiceLocator();

        $files = [
            'module' => [
                'label' => 'Module mapping files', // @translate
                'options' => [],
            ],
            'user' => [
                'label' => 'User mapping files', // @translate
                'options' => [],
            ],
        ];

        $this->directory = dirname(__DIR__, 2) . '/data/mapping/' . $subDirectory;
        $this->extension = $extension;

        $files['module']['options'] = $this->getFiles();

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->directory = $basePath . '/mapping/' . $subDirectory;
        $userFiles = $this->getFiles();
        foreach ($userFiles as $file) {
            $files['user']['options']['user: ' . $file] = $file;
        }

        return $files;
    }

    /**
     * Get all files available to sideload.
     *
     * Adapted from the method in module FileSideload.
     * @see \FileSideload\Media\Ingester\Sideload::getFiles();
     */
    protected function getFiles(): array
    {
        $files = [];
        $dir = new \SplFileInfo($this->directory);
        if ($dir->isDir()) {
            $lengthDir = strlen($this->directory) + 1;
            $dir = new \RecursiveDirectoryIterator($this->directory);
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
                    if ((!$this->extension || pathinfo($filepath, PATHINFO_EXTENSION) === $this->extension)
                        && $this->checkFile($file)
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
     */
    protected function checkFile(\SplFileInfo $fileinfo): ?string
    {
        if ($this->directory === false) {
            return null;
        }
        $realPath = $fileinfo->getRealPath();
        if ($realPath === false) {
            return null;
        }
        if (strpos($realPath, $this->directory) !== 0) {
            return null;
        }
        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return null;
        }
        return $realPath;
    }
}
