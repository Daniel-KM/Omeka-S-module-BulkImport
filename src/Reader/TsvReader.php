<?php declare(strict_types=1);
namespace BulkImport\Reader;

use Box\Spout\Common\Type;
use BulkImport\Form\Reader\SpreadsheetReaderConfigForm;
use BulkImport\Form\Reader\TsvReaderParamsForm;
use Laminas\Form\Form;
use Laminas\ServiceManager\ServiceLocatorInterface;

class TsvReader extends CsvReader
{
    protected $label = 'TSV (tab-separated values)'; // @translate
    protected $mediaType = 'text/tab-separated-values';
    protected $spreadsheetType = Type::CSV;
    protected $configFormClass = SpreadsheetReaderConfigForm::class;
    protected $paramsFormClass = TsvReaderParamsForm::class;

    protected $configKeys = [
        'separator',
    ];

    protected $paramsKeys = [
        'filename',
        'separator',
    ];

    public function __construct(ServiceLocatorInterface  $services)
    {
        parent::__construct($services);
        $this->delimiter = "\t";
        $this->enclosure = chr(0);
        $this->escape = chr(0);
    }

    public function handleParamsForm(Form $form): void
    {
        parent::handleParamsForm($form);
        $params = $this->getParams();
        $params['delimiter'] = "\t";
        $params['enclosure'] = chr(0);
        $params['escape'] = chr(0);
        $this->setParams($params);
    }

    protected function reset(): void
    {
        parent::reset();
        $this->delimiter = "\t";
        $this->enclosure = chr(0);
        $this->escape = chr(0);
    }
}
