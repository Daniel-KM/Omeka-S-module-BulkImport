<?php declare(strict_types=1);

/*
 * Copyright 2015-2024 Daniel Berthereau
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

use DomDocument;
use Exception;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Service\Exception\RuntimeException;
use Omeka\Stdlib\Message;
use XsltProcessor;

/**
 * @todo Use omeka cli.
 *
 * Process transformation of xml via xsl.
 */
class ProcessXslt extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $command;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct($command, string $tempDir)
    {
        $this->command = $command;
        $this->tempDir = $tempDir;
    }

    /**
     * Apply a process (xslt stylesheet) on an file (xml file) and save result.
     *
     * @param string $url Url of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If none, a temp file will
     * be used.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     * @throws \Exception
     */
    public function __invoke($url, $stylesheet, $output = '', array $parameters = []): ?string
    {
        // The readability is a very common error, so it is checked separately.
        // Furthermore, the input should be local to be processed by php or cli.
        $filepath = $url;
        $isRemote = $this->isRemote($url);
        if ($isRemote) {
            // TODO Use the Omeka temp dir.
            $filepath = @tempnam($this->tempDir, basename($url));
            $result = file_put_contents($filepath, file_get_contents($url));
            if (empty($result)) {
                throw new RuntimeException(sprintf(
                    'The remote file "%s" is not readable or empty.', // @translate
                    $url
                ));
            }
        } elseif (!is_file($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            throw new RuntimeException(sprint(
                'The input file "%s" is not readable.', // @translate
                $filepath
            ));
        }

        // Default is the internal xslt processor of php.
        $result = empty($this->command)
            ? $this->processXsltViaPhp($filepath, $stylesheet, $output, $parameters)
            : $this->processXsltViaExternal($filepath, $stylesheet, $output, $parameters);

        if ($isRemote) {
            unlink($filepath);
        }

        return $result;
    }

    /**
     * Apply a process (xslt stylesheet) on an input (xml file) and save output.
     *
     * @param string $url Path of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If none, a temp file will
     * be used.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     * @throws \Exception
     */
    protected function processXsltViaPhp($url, $stylesheet, $output = '', array $parameters = [])
    {
        if (empty($output)) {
            $output = @tempnam($this->tempDir, 'omk_xsl_') . '.xml';
        }

        try {
            $domXml = $this->domXmlLoad($url);
            $domXsl = $this->domXmlLoad($stylesheet);
        } catch (Exception $e) {
            throw $e;
        }

        // Don't accept warning (for example a missing file to import).
        libxml_use_internal_errors(true);

        $proc = new XSLTProcessor;
        $result = $proc->importStyleSheet($domXsl);
        if ($result === false) {
            $errors = $this->formatXmlErrors();
            libxml_use_internal_errors(false);
            throw new RuntimeException(sprintf(
                'An error occured during the xsl transformation of the file "%1$s" with the sheet "%2$s": %3$s', // @translate
                $this->isRemote($url) ? $url : basename($url),
                basename($stylesheet),
                implode('; ', $errors)
            ));
        }

        $proc->setParameter('', $parameters);
        $result = $proc->transformToURI($domXml, $output);
        @chmod($output, 0664);

        // There is no specific message for error with this processor.
        if ($result === false) {
            $errors = $this->formatXmlErrors();
            libxml_use_internal_errors(false);
            throw new RuntimeException(sprintf(
                'An error occured during the xsl transformation of the file "%1$s" with the sheet "%2$s": %3$s', // @translate
                $this->isRemote($url) ? $url : basename($url),
                basename($stylesheet),
                implode('; ', $errors)
            ));
        }

        return $output;
    }

    /**
     * Load a xml or xslt file into a Dom document via file system or http.
     *
     * @param string $filepath Path of xml file on file system or via http.
     * @return \DomDocument
     * @throws \Exception
     */
    protected function domXmlLoad($filepath)
    {
        libxml_use_internal_errors(true);

        $domDocument = new DomDocument;

        // If xml file is over http, need to get it locally to process xslt.
        if ($this->isRemote($filepath)) {
            $xmlContent = file_get_contents($filepath);
            if ($xmlContent === false) {
                $errors = $this->formatXmlErrors();
                libxml_use_internal_errors(false);
                if ($errors) {
                    throw new RuntimeException(sprintf(
                        'Could not load "%1$s". Verify that you have rights to access this folder and subfolders. %2$s', // @translate
                        basename($filepath),
                        implode('; ', $errors)
                    ));
                } else {
                    throw new RuntimeException(sprintf(
                        'Could not load "%s". Verify that you have rights to access this folder and subfolders.', // @translate
                        $filepath
                    ));
                }
            } elseif (empty($xmlContent)) {
                libxml_use_internal_errors(false);
                throw new RuntimeException(sprintf(
                    'The file "%s" is empty. Process is aborted.', // @translate
                    basename($filepath)
                ));
            }
            $domDocument->loadXML($xmlContent);
        }

        // Default import via file system.
        else {
            $domDocument->load($filepath);
        }

        return $domDocument;
    }

    /**
     * Apply a process (xslt stylesheet) on an input (xml file) and save output.
     *
     * @param string $url Path of input file.
     * @param string $stylesheet Path of the stylesheet.
     * @param string $output Path of the output file. If none, a temp file will
     * be used.
     * @param array $parameters Parameters array.
     * @return string|null Path to the output file if ok, null else.
     *
     * @todo Use omeka standard lib cli.
     */
    protected function processXsltViaExternal($url, $stylesheet, $output = '', $parameters = [])
    {
        if (empty($output)) {
            $output = @tempnam($this->tempDir, 'omk_xsl_') . '.xml';
        }

        $command = sprintf($this->command, escapeshellarg($url), escapeshellarg($stylesheet), escapeshellarg($output));
        foreach ($parameters as $name => $parameter) {
            $command .= ' ' . escapeshellarg($name . '=' . $parameter);
        }

        $result = shell_exec($command . ' 2>&1 1>&-');
        @chmod($output, 0640);

        // In Shell, empty is a correct result.
        if (!empty($result)) {
            throw new RuntimeException(sprintf(
                'An error occurs during the xsl transformation of the file "%s" with the sheet "%s" : %s', // @translate
                $this->isRemote($url) ? $url : basename($url),
                basename($stylesheet),
                $result
            ));
        }

        return $output;
    }

    /**
     * Determine if a uri is a remote url or a local path.
     *
     * @param string $url
     * @return bool
     */
    protected function isRemote($url)
    {
        return strpos((string) $url, 'http://') === 0
            || strpos((string) $url, 'https://') === 0
            || strpos((string) $url, 'ftp://') === 0
            || strpos((string) $url, 'sftp://') === 0;
    }

    protected function formatXmlErrors(): array
    {
        $errors = [];
        /** @var \LibXMLError $error */
        foreach (libxml_get_errors() as $error) {
            $errors[] = new Message(
                '%1$s (file %2$s, line %3$d)', // @translate
                $error->message, $error->file, $error->line
            );
        }
        return $errors;
    }
}
