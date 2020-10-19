<?php declare(strict_types=1);

/*
 * Copyright 2019 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use mikehaertl\pdftk\Pdf;

/**
 * Extract metadata from a pdf.
 */
class ExtractDataFromPdf extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $pdftkPath;

    /**
     * @var string
     */
    protected $executeStrategy;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param string $pdftkPath
     * @param string $executeStrategy
     * @param Logger $logger
     */
    public function __construct($pdftkPath, $executeStrategy, Logger $logger)
    {
        $this->pdftkPath = $pdftkPath;
        $this->executeStrategy = $executeStrategy;
        $this->logger = $logger;
    }

    /**
     * Extract medadata from a pdf.
     *
     * @param string $filepath
     * @return array
     */
    public function __invoke($filepath)
    {
        // $pdfproperties2dcterms = [
        //     'CreationDate' => 'dcterms:created',
        //     'ModDate' => 'dcterms:modified',
        //     'Title' => 'dcterms:title',
        //     'Author' => 'dcterms:creator',
        //     'Subject' => 'dcterms:description',
        //     'Keywords' => 'dcterms:subject',
        //     // Softwares (editor and generator).
        //     'Creator' => '',
        //     'Producer' => '',
        //     // TODO The table should be rebuild from pdf bookmarks.
        //     // BookmarkBegin
        //     // BookmarkTitle: <title in UTF8>
        //     // BookmarkLevel: <number>
        //     // BookmarkPageNumber: <number>
        //     'Bookmark' => 'dcterms:tableOfContents',
        //     // TODO Extract page size.
        // ];

        $options = [];
        if ($this->pdftkPath) {
            $options['command'] = $this->pdftkPath;
        }
        if ($this->executeStrategy === 'exec') {
            $options['useExec'] = true;
        }
        $pdf = new Pdf($filepath, $options);
        // TODO There is a bug in version 0.6.1, so remove notices.
        $data = (string) @$pdf->getData();
        if (empty($data)) {
            $error = $pdf->getError() ?: sprintf('Command pdftk unavailable or failed: %s', $pdf->getCommand()); // @translate
            $this->logger()->err(sprintf('Unable to process pdf: %s', $error));
            return [];
        }

        $result = [];

        $regex = '~^InfoBegin\nInfoKey: (.+)\nInfoValue: (.+)$~m';
        $matches = [];
        preg_match_all($regex, $data, $matches, PREG_SET_ORDER, 0);
        foreach ($matches as $match) {
            $result[$match[1]] = $match[2];
        }

        $regex = '~^NumberOfPages: (\d+)$~m';
        preg_match($regex, $data, $matches);
        if ($matches[1]) {
            $result['NumberOfPages'] = $matches[1];
        }

        return $result;
    }

    protected function logger()
    {
        return $this->logger;
    }
}
