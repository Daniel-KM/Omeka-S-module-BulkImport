<?php declare(strict_types=1);

namespace BulkImport\Controller\Admin;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
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

    public function __construct(
        TempFileFactory $tempFileFactory,
        Validator $validator
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
    }

    public function indexAction()
    {
        $user = $this->identity();
        if (!$user) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_403);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('User not authenticated.'), // @translate
                'code' => Response::STATUS_CODE_403,
            ]);
        }

        /** @var \Laminas\Http\Request $request*/
        $request = $this->getRequest();
        $headers = $request->getHeaders()->toArray();

        /** @var \Laminas\View\Helper\ServerUrl $serverUrl */
        $serverUrl = $this->viewHelpers()->get('ServerUrl');
        if ($serverUrl->getHost() !== $headers['Host'] ?? null) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_403);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('The request must originate from the server.'), // @translate
                'code' => Response::STATUS_CODE_403,
            ]);
        }

        // Check csrf for security.
        $form = $this->getForm(\Omeka\Form\ResourceForm::class);
        $form->setData(['csrf' => $headers['X-Csrf'] ?? null]);
        if (!$form->isValid()) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_403);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('Invalid or missing CSRF token'), // @translate
                'code' => Response::STATUS_CODE_403,
            ]);
        }

        $contentLength = $headers['Content-Length'] ?? 0;
        if (!$contentLength) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_412);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('The file is empty.'), // @translate
                'code' => Response::STATUS_CODE_412,
            ]);
        }

        $filename = (string) ($headers['X-Filename'] ?? '');
        if (strlen($filename) < 3 || strlen($filename) > 200) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_412);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('Filename too much short or long.'), // @translate
                'code' => Response::STATUS_CODE_412,
            ]);
        }

        if (substr($filename, 0, 1) === '.' || strpos($filename, '/') !== false) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_412);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('Filename contains forbidden characters.'), // @translate
                'code' => Response::STATUS_CODE_412,
            ]);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!strlen($extension)) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_412);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('Filename has no extension.'), // @translate
                'code' => Response::STATUS_CODE_412,
            ]);
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($filename);
        $filepath = $tempFile->getTempPath();
        $filesize = file_put_contents($filepath, $request->getContent());
        if ($filesize === false) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_500);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('Unable to store the file.'), // @translate
                'code' => Response::STATUS_CODE_500,
            ]);
        }

        if (!$filesize) {
            $response = $this->getResponse();
            $response->setStatusCode(Response::STATUS_CODE_412);
            @unlink($filepath);
            return new JsonModel([
                'status' => self::STATUS_ERROR,
                'message' => $this->translate('The file is empty.'), // @translate
                'code' => Response::STATUS_CODE_412,
            ]);
        }

        $validateFile = (bool) $this->settings()->get('disable_file_validation', false);
        if ($validateFile) {
            $errorStore = new ErrorStore();
            if (!$this->validator->validate($tempFile, $errorStore)) {
                $response = $this->getResponse();
                $response->setStatusCode(Response::STATUS_CODE_415);
                @unlink($filepath);
                $errors = $errorStore->getErrors();
                $errors = reset($errors);
                $error = is_array($errors) ? reset($errors) : $errors;
                return new JsonModel([
                    'status' => self::STATUS_ERROR,
                    'message' => $this->translate($error),
                    'code' => Response::STATUS_CODE_415,
                ]);
            }
        }

        // Return the data about the file for next step.
        return new JsonModel([
            'status' => self::STATUS_SUCCESS,
            'data' => [
                'file' => [
                    'name' => $filename,
                    // Don't send the full path for security.
                    'tmp_name' => basename($filepath),
                    'size' => $filesize,
                ],
            ],
        ]);
    }
}
