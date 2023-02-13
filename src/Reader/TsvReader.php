<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Form\Reader\SpreadsheetReaderConfigForm;
use BulkImport\Form\Reader\TsvReaderParamsForm;
use Laminas\Form\Form;
use Laminas\ServiceManager\ServiceLocatorInterface;
use OpenSpout\Common\Type;

class TsvReader extends CsvReader
{
    protected $label = 'TSV (tab-separated values)'; // @translate
    protected $mediaType = 'text/tab-separated-values';
    protected $spreadsheetType = Type::CSV;
    protected $configFormClass = SpreadsheetReaderConfigForm::class;
    protected $paramsFormClass = TsvReaderParamsForm::class;

    protected $configKeys = [
        'url',
        'separator',
    ];

    protected $paramsKeys = [
        'filename',
        'url',
        'separator',
    ];

    public function __construct(ServiceLocatorInterface  $services)
    {
        parent::__construct($services);
        $this->delimiter = "\t";
        $this->enclosure = chr(0);
        $this->escape = chr(0);
    }

    public function handleParamsForm(Form $form): self
    {
        parent::handleParamsForm($form);
        $params = $this->getParams();
        $params['delimiter'] = "\t";
        $params['enclosure'] = chr(0);
        $params['escape'] = chr(0);
        $this->setParams($params);
        $this->appendInternalParams();
        return $this;
    }

    protected function reset(): self
    {
        parent::reset();
        $this->delimiter = "\t";
        $this->enclosure = chr(0);
        $this->escape = chr(0);
        return $this;
    }

    protected function isValidFilepath($filepath, array $file = []): bool
    {
        // On some servers, type for csv is "application/vnd.ms-excel".
        if (!empty($file['type']) && $file['type'] === 'application/vnd.ms-excel') {
            $file['type'] = 'text/tab-separated-values';
        }

        return parent::isValidFilepath($filepath, $file);
    }
}
