<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Stdlib\ErrorStore;

class UploadController extends AbstractActionController
{
    const STATUS_SUCCESS = 'success';
    const STATUS_ERROR = 'error';

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct(
        TempFileFactory $tempFileFactory,
        Validator $validator,
        string $tempDir
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->tempDir = $tempDir;
    }

    /**
     * Save a file locally for future ingesting.
     *
     * For each chunk, Flow.js sends a get with a query, then a post with the
     * same query but with the chunk too as file. It allows to resume broken
     * downloads without restarting.
     *
     * @see \Flow\Basic::save()
     */
    public function indexAction()
    {
        // TODO Fix issue with session warning appended to json (currently fixed via js): Warning: session_write_close(): Failed to write session data using user defined save handler.

        // Some security checks.

        $user = $this->identity();
        if (!$user) {
            return $this->jsonError(
                $this->translate('User not authenticated.'), // @translate
                Response::STATUS_CODE_403
            );
        }

        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $headers = $request->getHeaders()->toArray();

        /** @var \Laminas\View\Helper\ServerUrl $serverUrl */
        $serverUrl = $this->viewHelpers()->get('ServerUrl');
        if ($serverUrl->getHost() !== ($headers['Host'] ?? null)) {
            return $this->jsonError(
                $this->translate('The request must originate from the server.'), // @translate
                Response::STATUS_CODE_403
            );
        }

        // Check csrf for security.
        $form = $this->getForm(\Omeka\Form\ResourceForm::class);
        $form->setData(['csrf' => $headers['X-Csrf'] ?? null]);
        if (!$form->isValid()) {
            return $this->jsonError(
                $this->translate('Invalid or missing CSRF token.'), // @translate
                Response::STATUS_CODE_403
            );
        }

        // Processing the chunk.

        $flowConfig = new \Flow\Config([
            'tempDir' => $this->tempDir,
        ]);

        // $chunk = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $flowRequest = new \Flow\Request();

        $uploadFileName = 'omk'
            . '_' . (new \DateTime())->format('Ymd-His')
            . '_' . substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 8)
            . '_' . $flowRequest->getFileName();
        $destination = $this->tempDir . DIRECTORY_SEPARATOR . $uploadFileName;

        try {
            $file = new \Flow\File($flowConfig, $flowRequest);
            if ($request->isGet()) {
                if ($file->checkChunk()) {
                    // Nothing to do here.
                } else {
                    // The 204 response MUST NOT include a message-body, and
                    // thus is always terminated by the first empty line after
                    // the header fields.
                    $this->getResponse()
                        ->setStatusCode(Response::STATUS_CODE_204);
                    // Don't use view model, there is no template.
                    return (new JsonModel())
                        ->setTerminal(true);
                }
            } else {
                if ($file->validateChunk()) {
                    $file->saveChunk();
                } else {
                    // Error, invalid chunk upload request, retry.
                    $this->getResponse()
                        ->setStatusCode(Response::STATUS_CODE_400);
                    // Don't use view model, there is no template.
                    return (new JsonModel())
                        ->setTerminal(true);
                }
            }
        } catch (\Exception $e) {
            $this->logger()->err($e);
            return (new JsonModel())
                ->setTerminal(true);
        }

        // Check if this is the last chunk.
        if (!$file->validateFile() || !$file->save($destination)) {
            return (new JsonModel())
                ->setTerminal(true);
        }

        // File is saved successfully and can be accessed at destination.

        // Ingester checks.

        $allowEmptyFiles = (bool) $this->settings()->get('bulkimport_allow_empty_files', false);
        $filesize = filesize($destination);
        if (!$filesize && !$allowEmptyFiles) {
            return $this->jsonError(
                $this->translate('The file is empty.'), // @translate
                Response::STATUS_CODE_412
            );
        }

        $filename = (string) $flowRequest->getFileName();
        if (strlen($filename) < 3 || strlen($filename) > 200) {
            return $this->jsonError(
                $this->translate('Filename too much short or long.'), // @translate
                Response::STATUS_CODE_412
            );
        }

        if (substr($filename, 0, 1) === '.' || strpos($filename, '/') !== false) {
            return $this->jsonError(
                $this->translate('Filename contains forbidden characters.'), // @translate
                Response::STATUS_CODE_412
            );
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!strlen($extension)) {
            return $this->jsonError(
                $this->translate('Filename has no extension.'), // @translate
                Response::STATUS_CODE_412
            );
        }

        $filepath = $destination;
        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($filename);
        $tempFile->setTempPath($filepath);

        $validateFile = (bool) $this->settings()->get('disable_file_validation', false);
        if ($validateFile) {
            $errorStore = new ErrorStore();
            if (!$this->validator->validate($tempFile, $errorStore)) {
                @unlink($filepath);
                $errors = $errorStore->getErrors();
                $errors = reset($errors);
                $error = is_array($errors) ? reset($errors) : $errors;
                return $this->jsonError(
                    $this->translate($error),
                    Response::STATUS_CODE_415
                );
            }
        }

        $isBulkImport = !empty($headers['X-Is-Bulk-Import']);
        if ($isBulkImport) {
            $localPath = $this->settings()->get('bulkimport_local_path');
            if (!$localPath) {
                return $this->jsonError(
                    $this->translate('Local path is not configured.'), // @translate
                    Response::STATUS_CODE_500
                );
            }
            $localPath = $this->checkDestinationDir($localPath);
            if (!$localPath) {
                return $this->jsonError(
                    $this->translate('Local path is not writeable.'), // @translate
                    Response::STATUS_CODE_500
                );
            }
            $newDestination = rtrim($localPath, '//') . '/' . $filename;
            $fileExists = file_exists($newDestination);
            if ($fileExists && !is_writeable($newDestination)) {
                return $this->jsonError(
                    $this->translate('A file with the same name exists and is not writeable.'), // @translate
                    Response::STATUS_CODE_500
                );
            }
            $result = rename($destination, $newDestination);
            if (!$result) {
                return $this->jsonError(
                    $this->translate('An error occurred when storing file.'), // @translate
                    Response::STATUS_CODE_500
                );
            }
        }

        // Default files used to keep place in temp directory are not removed.
        $this->cleanTempDirectory();

        // Return the data about the file for next step.
        return new JsonModel([
            'status' => self::STATUS_SUCCESS,
            'data' => [
                'file' => [
                    'name' => $filename,
                    'path' => $flowRequest->getRelativePath(),
                    // Don't send the full path for security.
                    'tmp_name' => basename($filepath),
                    'size' => $filesize,
                ],
            ],
        ]);
    }

    public function filesAction()
    {
        // Check omeka setting for files.
        $settings = $this->settings();
        if ($settings->get('disable_file_validation', false)) {
            $allowedMediaTypes = '';
            $allowedExtensions = '';
        } else {
            $allowedMediaTypes = $settings->get('media_type_whitelist', []);
            $allowedExtensions = $settings->get('extension_whitelist', []);
            $allowedMediaTypes = implode(',', $allowedMediaTypes);
            $allowedExtensions = implode(',', $allowedExtensions);
        }

        $allowEmptyFiles = (bool) $settings->get('bulkimport_allow_empty_files', false);

        $data = [
            // This option allows to manage resource form and bulk upload form.
            'data-bulk-upload' => true,
            'data-csrf' => (new \Laminas\Form\Element\Csrf('csrf'))->getValue(),
            'data-allowed-media-types' => $allowedMediaTypes,
            'data-allowed-extensions' => $allowedExtensions,
            'data-allow-empty-files' => (int) $allowEmptyFiles,
            'data-translate-pause' => $this->translate('Pause'), // @translate
            'data-translate-resume' => $this->translate('Resume'), // @translate
            'data-translate-no-file' => $this->translate('No files currently selected for upload'), // @translate
            'data-translate-invalid-file' => $allowEmptyFiles
                ? $this->translate('Not a valid file type or extension. Update your selection.') // @translate
                : $this->translate('Not a valid file type, extension or size. Update your selection.'), // @translate
            'data-translate-unknown-error' => $this->translate('An issue occurred.'), // @translate
        ];

        return new ViewModel([
            'data' => $data,
        ]);
    }

    protected function jsonError(string $message, int $statusCode = 400)
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        return new JsonModel([
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'code' => $statusCode,
        ]);
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath Absolute path.
     * @return string|null
     */
    protected function checkDestinationDir(string $dirPath): ?string
    {
        if (file_exists($dirPath)) {
            if (!is_dir($dirPath) || !is_readable($dirPath) || !is_writeable($dirPath)) {
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    'The directory "{path}" is not writeable.', // @translate
                    ['path' => $dirPath]
                );
                return null;
            }
            return $dirPath;
        }

        $result = @mkdir($dirPath, 0775, true);
        if (!$result) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                'The directory "{path}" is not writeable: {error}.', // @translate
                ['path' => $dirPath, 'error' => error_get_last()['message']]
            );
            return null;
        }
        return $dirPath;
    }

    protected function cleanTempDirectory()
    {
        if (!$this->tempDir) {
            return;
        }
        $fileSystemIterator = new \FilesystemIterator($this->tempDir);
        $threshold = strtotime('-30 day');
        /** @var \SplFileInfo $file */
        foreach ($fileSystemIterator as $file) {
            $filename = $file->getFilename();
            if ($file->isFile()
                && $file->isWritable()
                && $file->getCTime() <= $threshold
                && (
                    // Remove any old placeholder.
                    !$file->getSize()
                    // Remove all old omeka temp files.
                    || (mb_substr($filename, 0, 5) === 'omeka' || mb_substr($filename, 0, 4) === 'omk_')
                )
            ) {
                @unlink($this->tempDir . '/' . $file->getFilename());
            }
        }
    }
}
