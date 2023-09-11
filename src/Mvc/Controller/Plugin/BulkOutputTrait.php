<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

trait BulkOutputTrait
{
    /**
     * Create the unique file name compatible on various os.
     *
     * Note: the destination dir is created during install.
     *
     * @return string Path to the return path.
     */
    protected function prepareFile(array $params): ?string
    {
        $basename = $params['name'] ?? '';
        $extension = $params['extension'] ?? '';
        $appendDate = !empty($params['append_date']);

        $destinationDir = $this->basePath . '/bulk_import';

        $base = (string) preg_replace('/[^A-Za-z0-9-]/', '_', $basename);
        $base = substr(preg_replace('/_+/', '_', $base), 0, 20);

        if ($appendDate) {
            if (strlen($base)) {
                $base .= '-';
            }
            $date = (new \DateTime())->format('Ymd-His');
        } elseif (!strlen($base)) {
            $base = 'bi';
            $date = '';
        } else {
            $date = '';
        }

        // Avoid issue on very big base.
        $i = 0;
        do {
            $filename = sprintf(
                '%s%s%s%s',
                $base,
                $date,
                $i ? '-' . $i : '',
                $extension ? '.' . $extension : ''
            );

            $filePath = $destinationDir . '/' . $filename;
            if (!file_exists($filePath)) {
                try {
                    $result = @touch($filePath);
                } catch (\Exception $e) {
                    $this->logger->err(
                        // $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {exception}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'exception' => $e]
                    );
                    return null;
                }

                if (!$result) {
                    // $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                    $this->logger->err(
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {error}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'error' => error_get_last()['message']]
                    );
                    return null;
                }

                break;
            }
        } while (++$i);

        return $filePath;
    }
}
