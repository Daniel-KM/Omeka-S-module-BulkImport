<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Form\Element\OptionalSelect;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class JsonReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
    {
        $this
            ->add([
                'name' => 'url',
                'type' => BulkImportElement\OptionalUrl::class,
                'options' => [
                    'label' => 'Json url', // @translate
                ],
                'attributes' => [
                    'id' => 'url',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'list_files',
                'type' => OmekaElement\ArrayTextarea::class,
                'options' => [
                    'label' => 'List of files or urls', // @translate
                ],
                'attributes' => [
                    'id' => 'list_files',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'mapping_file',
                'type' => OptionalSelect::class,
                'options' => [
                    'label' => 'Mapping file used to convert source', // @translate
                    'info' => 'Default mapping are located in "modules/BulkImport/data/mapping" and user ones in "files/mapping".', // @translate
                    'value_options' => $this->listFiles(),
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'mapping_file',
                    'value' => '',
                    'class' => 'chosen-select',
                    'required' => true,
                    'data-placeholder' => 'Select the mapping for conversion…', // @translate
                ],
            ])
            ->add([
                'name' => 'mapping_automatic',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'id' => 'mapping_automatic',
                    'value' => '1',
                ],
            ])
        ;

        parent::init();
    }

    protected function listFiles(): array
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

        $this->directory = dirname(__DIR__, 3) . '/data/mapping';
        $files['module']['options'] = $this->getFiles();

        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->directory = $basePath . '/mapping';
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
                if (pathinfo($filepath, PATHINFO_EXTENSION) === 'ini' && $this->checkFile($file)) {
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
