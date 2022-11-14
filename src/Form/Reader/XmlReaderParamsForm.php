<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use Laminas\Form\Element;

class XmlReaderParamsForm extends XmlReaderConfigForm
{
    /**
     * @see \XmlViewer
     *
     * @var array
     */
    const MEDIATYPES_XML = [
        'application/xml',
        'text/xml',

        'application/alto+xml',
        'application/atom+xml',
        'application/ead+xml', // Unofficial.
        'application/marcxml+xml',
        'application/mets+xml',
        'application/mods+xml',
        'application/rss+xml',
        'application/tei+xml',
        'application/xhtml+xml',

        'application/vnd.alto+xml', // Deprecated in 2017.
        'application/vnd.bnf.refnum+xml',
        'application/vnd.ead+xml',
        'application/vnd.iccu.mag+xml',
        'application/vnd.marc21+xml', // Deprecated in 2011.
        'application/vnd.mei+xml',
        'application/vnd.mets+xml', // Deprecated in 2011.
        'application/vnd.mods+xml', // Deprecated in 2011.
        'application/vnd.openarchives.oai-pmh+xml',
        'application/vnd.pdf2xml+xml', // Used in module IIIF Search.
        'application/vnd.recordare.musicxml+xml',
        'application/vnd.tei+xml', // Deprecated in 2011.

        'application/vnd.oasis.opendocument.presentation-flat-xml',
        'application/vnd.oasis.opendocument.spreadsheet-flat-xml',
        'application/vnd.oasis.opendocument.text-flat-xml',
        'application/vnd.openarchives.oai-pmh+xml',

        'text/html',
        'text/vnd.hocr+html', // Unofficial, not standard xml anyway. And recommended is alto.
        'text/vnd.omeka+xml',
    ];

    public function init(): void
    {
        // Set binary content encoding
        $this->setAttribute('enctype', 'multipart/form-data');

        $this
            ->add([
                'name' => 'file',
                'type' => Element\File::class,
                'options' => [
                    'label' => 'XML file', // @translate
                ],
                'attributes' => [
                    'id' => 'file',
                    'required' => false,
                    'accept' => implode(',', self::MEDIATYPES_XML)
                        . ',alto,atom,ead,feed,fodp,fods,fodt,html,mag,mei,mets,mods,musicxml,mxl,pdf2xml,refnum,rss,tei,xhtml,xml',
                ],
            ])
        ;

        parent::init();
    }
}
