<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

// The autoload doesn’t work with GetId3.
if (!class_exists(\JamesHeinrich\GetID3\GetId3::class)) {
    require_once dirname(__DIR__, 4) . '/vendor/james-heinrich/getid3/src/GetID3.php';
}

use BulkImport\Stdlib\MetaMapper;
use JamesHeinrich\GetID3\GetId3;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Log\Stdlib\PsrMessage;
use Omeka\Entity\Media;

/**
 * Extract metadata from a file.
 */
class ExtractMediaMetadata extends AbstractPlugin
{
    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \BulkImport\Stdlib\MetaMapper
     */
    protected $metaMapper;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\ExtractDataFromPdf
     */
    protected $extractDataFromPdf;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var bool
     */
    protected $logExtractedMetadata = false;

    /**
     * Data to remove from output for security.
     *
     * @var array
     */
    protected $ignoredKeys = [
        'GETID3_VERSION',
        // 'filesize',
        'filename',
        'filepath',
        'filenamepath',
        'avdataoffset',
        'avdataend',
        'fileformat',
        'encoding',
        // 'mime_type',
        // 'md5_data',
    ];

    public function __construct(
        Logger $logger,
        MetaMapper $metaMapper,
        ExtractDataFromPdf $extractDataFromPdf,
        string $basePath,
        bool $logExtractedMetadata
    ) {
        $this->logger = $logger;
        $this->metaMapper = $metaMapper;
        $this->extractDataFromPdf = $extractDataFromPdf;
        $this->basePath = $basePath;
        $this->logExtractedMetadata = $logExtractedMetadata;
    }

    /**
     * Extract medadata from a media according to a map by media type.
     *
     * @todo Manage cloud paths.
     */
    public function __invoke(Media $media): ?array
    {
        $mediaType = $media->getMediaType();
        if (!$mediaType) {
            return null;
        }

        $filename = $media->getFilename();
        if (!$filename) {
            return null;
        }

        $filepath = $this->basePath . '/original/' . $filename;
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return null;
        }

        // Prepare meta config.
        // Adapted from AbstractResourceProcessor.
        // TODO Merge with AbstractResourceProcessor.
        // Here, the mapping reference is used as mapping name.
        $mappingReference = 'module:json/file.' . str_replace('/', '_', $mediaType). '.jsdot.ini';
        $mapping = $this->metaMapper->getMetaMapperConfig()->__invoke($mediaType, $mappingReference);
        if ($mapping === null) {
            return null;
        }

        if (!empty($mapping['has_error'])) {
            if ($mapping['has_error'] === true) {
                $this->logger->err(new PsrMessage(
                    'Error in the mapping config "{name}".', // @translate
                    ['name' => $mappingReference]
                ));
            } else {
                $this->logger->err(new PsrMessage(
                    'Error in the mapping config: {message}', // @translate
                    ['message' => $mapping['has_error']]
                ));
            }
            return null;
        }

        // Currently, only two extractors: pdf and media files.
        switch ($mediaType) {
            case 'application/pdf':
                $data = $this->extractDataFromPdf->__invoke($filepath);
                break;
            default:
                $getId3 = new GetId3();
                $data = $getId3->analyze($filepath);
                $data = array_diff_key($data, array_flip($this->ignoredKeys));
                break;
        }

        if ($this->logExtractedMetadata) {
            $this->logger->info(new PsrMessage(
                'Extracted metadata for media #{media_id}: {json}', // @translate
                // Use pretty print and JSON_INVALID_UTF8_SUBSTITUTE.
                ['media_id' => $media->getId(), 'json' => json_encode($data, 2097600)]
            ));
        }

        $resource = $this->metaMapper->__invoke($mappingReference)->convert($data);
        return $resource;
    }
}
