<?php declare(strict_types=1);

namespace BulkImport\Processor;

use DateTime;
use DateTimeZone;

/**
 * Contains code adapted from module \NumericDataTypes\DataType\AbstractDateTimeDataType:
 * methods getDateTimeFromValue (without cache and returns only DateTime, no exception) and
 * getLastDay (of a month).
 * If a cache is needed, use original static method.
 */
trait DateTimeTrait
{
    /**
     * Minimum and maximum years.
     *
     * When converted to Unix timestamps, anything outside this range would
     * exceed the minimum or maximum range for a 64-bit integer.
     *
     * @var int
     */
    protected $yearMin = -292277022656;

    /**
     * @var int
     */
    protected $yearMax = 292277026595;

    /**
     * ISO 8601 datetime pattern
     *
     * The standard permits the expansion of the year representation beyond
     * 0000–9999, but only by prior agreement between the sender and the
     * receiver. Given that our year range is unusually large we shouldn't
     * require senders to zero-pad to 12 digits for every year. Users would have
     * to a) have prior knowledge of this unusual requirement, and b) convert
     * all existing ISO strings to accommodate it. This is needlessly
     * inconvenient and would be incompatible with most other systems. Instead,
     * we require the standard's zero-padding to 4 digits, but stray from the
     * standard by accepting non-zero padded integers beyond -9999 and 9999.
     *
     * Note that we only accept ISO 8601's extended format: the date segment
     * must include hyphens as separators, and the time and offset segments must
     * include colons as separators. This follows the standard's best practices,
     * which notes that "The basic format should be avoided in plain text."
     *
     * @var string
     */
    protected $patternIso8601 = '^(?<date>(?<year>-?\d{4,})(-(?<month>\d{2}))?(-(?<day>\d{2}))?)(?<time>(T(?<hour>\d{2}))?(:(?<minute>\d{2}))?(:(?<second>\d{2}))?)(?<offset>((?<offset_hour>[+-]\d{2})?(:(?<offset_minute>\d{2}))?)|Z?)$';

    /**
     * Strangely, the "timestamp" may have date time data.
     *
     * Furthermore, a check is done because mysql allows only 1000-9999, but
     * there may be bad dates.
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/datetime.html
     *
     * @var ?string $timeZone The time zone can be "true" to use the Omeka one.
     */
    protected function getSqlDateTime($date, $timeZone = null): ?DateTime
    {
        if (empty($date)) {
            return null;
        }

        try {
            $date = (string) $date;
        } catch (\Exception $e) {
            return null;
        }

        if (in_array(substr($date, 0, 10), [
            '0000-00-00',
            '2038-01-01',
        ])) {
            return null;
        }

        if (substr($date, 0, 10) === '1970-01-01'
            && substr($date, 13, 6) === ':00:00'
        ) {
            return null;
        }

        $dateTimeZone = null;
        if ($timeZone) {
            if ($timeZone === true) {
                $timeZone = $this->getServiceLocator()->get('Omeka\Settings')->get('time_zone');
            }
            try {
                $dateTimeZone = new \DateTimeZone($timeZone);
                $dateTimeZone->setTimezone($dateTimeZone);
            } catch (\Exception $e) {
                $dateTimeZone = null;
            }
        }

        try {
            $dateTime = strpos($date, ':', 1) || strpos($date, '-', 1)
                ? new DateTime(substr(str_replace('T', ' ', $date), 0, 19), $dateTimeZone)
                : new DateTime(date('Y-m-d H:i:s', $date), $dateTimeZone);
        } catch (\Exception $e) {
            return null;
        }

        if ($dateTimeZone) {
            $dateTime->setTimezone(new \DateTimeZone('UTC'));
        }

        $formatted = $dateTime->format('Y-m-d H:i:s');
        return $formatted < '1000-01-01 00:00:00' || $formatted > '9999-12-31 23:59:59'
            ? null
            : $dateTime;
    }

    protected function literalFullDateOrDateTime(DateTime $dateTime): string
    {
        [$date, $time] = explode(' ', $dateTime->format('Y-m-d H:i:s'));
        return $time === '00:00:00'
            ? $date
            : $date . 'T' . $time;
    }

    /**
     * Create the timestamp with a quick sql query and numeric datatype methods.
     */
    protected function reindexNumericTimestamp(array $ids): void
    {
        if (!count($ids) || !$this->bulk->isDataType('numeric:timestamp')) {
            return;
        }

        $ids = array_values(array_unique($ids));

        // Only created resources.

        // To avoid issues with big import, use a temporary table for ids.

        $sql = <<<'SQL'
# Create a table with all created resource ids.
DROP TABLE IF EXISTS `_temporary_rid`;
CREATE TABLE `_temporary_rid` (
    `id` int(11) NOT NULL,
    UNIQUE (`id`)
);

SQL;
        foreach (array_chunk($ids, self::CHUNK_RECORD_IDS) as $chunk) {
            $sql .= 'INSERT INTO `_temporary_rid` (`id`) VALUES(' . implode('),(', $chunk) . ");\n";
        }

        $sql .= <<<'SQL'
# Remove numeric timestamp for the resource ids.
DELETE `numeric_data_types_timestamp`
FROM `numeric_data_types_timestamp`
JOIN `_temporary_rid` ON `_temporary_rid`.`id` = `numeric_data_types_timestamp`.`resource_id`;

SQL;

        $this->connection->executeStatement($sql);

        // Create timestamps.
        // Use static \NumericDataTypes\DataType\Timestamp::getDateTimeFromValue()
        // because they can be larger than 1970-2038, badly formatted or copied
        // in particular for years.
        // Nevertheless, the original method cannot be used, because it caches
        // various results and may be memory intensive.

        $sql = <<<'SQL'
# Create a table with all converted dates in order to process dates one time only.
DROP TABLE IF EXISTS `_temporary_date`;
CREATE TABLE `_temporary_date` (
    `value` LONGTEXT NULL,
    `timestamp` BIGINT(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `_temporary_date` (`value`)
SELECT DISTINCT
    `value`.`value`
FROM `value`
JOIN `_temporary_rid` ON `_temporary_rid`.`id` = `value`.`resource_id`
WHERE
    `value`.`type` = 'numeric:timestamp'
;
SQL;

        $this->connection->executeStatement($sql);

        $sql = <<<'SQL'
SELECT `value` FROM `_temporary_date`;
SQL;
        $stmt = $this->connection->executeQuery($sql);
        // TODO Add a loop for big source sizes or increase database and php memory.
        $result = $stmt->fetchFirstColumn();
        if (count($result)) {
            $result = array_fill_keys($result, null);
            foreach ($result as $datetime => &$timestamp) {
                try {
                    $timestamp = $this->getDateTimeFromValue($datetime, true);
                    if (!$timestamp) {
                        continue;
                    }
                } catch (\Exception $e) {
                    continue;
                }
                $timestamp = $timestamp->getTimestamp();
            }
            unset($timestamp);

            // Copy timestamps in destination table.
            $timestamps = array_filter($result);
            if (count($timestamps)) {
                foreach (array_chunk($timestamps, self::CHUNK_SIMPLE_RECORDS, true) as $chunk) {
                    $sql = '';
                    foreach ($chunk as $datetime => $timestamp) {
                        $value = $this->connection->quote($datetime);
                        $sql .= "UPDATE `_temporary_date` SET `timestamp` = '$timestamp' WHERE `value` = $value;\n";
                    }
                    $this->connection->executeStatement($sql);
                }

                $sql = <<<'SQL'
# Copy timestamps in destination table.
INSERT INTO `numeric_data_types_timestamp`
    (`resource_id`, `property_id`, `value`)
SELECT DISTINCT
    `value`.`resource_id`,
    `value`.`property_id`,
    `_temporary_date`.`timestamp`
FROM `value`
JOIN `_temporary_rid` ON `_temporary_rid`.`id` = `value`.`resource_id`
JOIN `_temporary_date` ON `_temporary_date`.`value` = `value`.`value`
WHERE
    `value`.`type` = 'numeric:timestamp'
    AND `_temporary_date`.`timestamp` IS NOT NULL
;
SQL;
                $this->connection->executeStatement($sql);
            }

            // Clean data type for numeric:timestamp.
            $notTimestamps = array_filter($result, 'is_null');
            if (count($notTimestamps)) {
                $sql = <<<'SQL'
UPDATE `value`
JOIN `_temporary_rid` ON `_temporary_rid`.`id` = `value`.`resource_id`
JOIN `_temporary_date` ON `_temporary_date`.`value` = `value`.`value`
SET
    `value`.`type` = "literal"
WHERE
    `value`.`type` = 'numeric:timestamp'
    AND `_temporary_date`.`timestamp` IS NULL
;
SQL;
                $this->connection->executeStatement($sql);

                $this->logger->warn(
                    '{count} values were not valid dates and were converted into literal.', // @translate
                    ['count' => count($notTimestamps)]
                );
                $this->logger->warn(
                    'Here are the first invalid dates: {list}.', // @translate
                    ['list' => implode(', ', array_slice(array_keys($notTimestamps), 0, 100))]
                );
            }
        }

        $sql = <<<SQL
DROP TABLE IF EXISTS `_temporary_rid`;
DROP TABLE IF EXISTS `_temporary_date`;
SQL;
        $this->connection->executeStatement($sql);
    }

    /**
     * Gather date/time components.
     *
     * Some databases store each date time components separately, so they may
     * need to be merged.
     *
     * @return DateTime|string|null
     */
    protected function implodeDate(
        $year,
        $month = null,
        $day = null,
        $hour = null,
        $minute = null,
        $second = null,
        $epoch = null,
        bool $formatted = false,
        bool $fullDatetime = false,
        bool $forSql = false
    ) {
        if ((int) $epoch) {
            return $this->getDateTimeFromValue(date('Y-m-d\TH:i:s', (int) $epoch), true, $formatted, $fullDatetime, $forSql);
        }
        if (!$year) {
            return null;
        }
        // Stringify according to granularity if some last parts are missing.
        if (strlen((string) $second)) {
            $string = sprintf('%04d-%02d-%02dT%02d:%02d:%02d', (int) $year, (int) $month, (int) $day, (int) $hour, (int) $minute, (int) $second);
        } elseif (strlen((string) $minute)) {
            $string = sprintf('%04d-%02d-%02dT%02d:%02d', (int) $year, (int) $month, (int) $day, (int) $hour, (int) $minute);
        } elseif (strlen((string) $hour)) {
            $string = sprintf('%04d-%02d-%02dT%02d', (int) $year, (int) $month, (int) $day, (int) $hour);
        } elseif (strlen((string) $day)) {
            $string = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
        } elseif (strlen((string) $month)) {
            $string = sprintf('%04d-%02d', (int) $year, (int) $month);
        } elseif (strlen((string) $year)) {
            $string = sprintf('%04d', (int) $year);
        } else {
            return null;
        }
        return $string
            ? $this->getDateTimeFromValue($string, true, $formatted, $fullDatetime, $forSql)
            : null;
    }

    /**
     * Get the decomposed date/time and DateTime object from an ISO 8601 value.
     *
     * Use $defaultFirst to set the default of each datetime component to its
     * first (true) or last (false) possible integer, if the specific component
     * is not passed with the value.
     *
     * Also used to validate the datetime since validation is a side effect of
     * parsing the value into its component datetime pieces.
     *
     * @return DateTime|string|null
     */
    protected function getDateTimeFromValue($value, bool $defaultFirst = true, bool $formatted = false, bool $fullDatetime = false, bool $forSql = false)
    {
        // Match against ISO 8601, allowing for reduced accuracy.
        $matches = [];
        $isMatch = preg_match('/' . $this->patternIso8601 . '/', (string) $value, $matches);
        if (!$isMatch) {
            return null;
        }
        $matches = array_filter($matches); // remove empty values
        // An hour requires a day.
        if (isset($matches['hour']) && !isset($matches['day'])) {
            return null;
        }
        // An offset requires a time.
        if (isset($matches['offset']) && !isset($matches['time'])) {
            return null;
        }

        // Set the datetime components included in the passed value.
        $dateTime = [
            'value' => $value,
            'date_value' => $matches['date'],
            'time_value' => $matches['time'] ?? null,
            'offset_value' => $matches['offset'] ?? null,
            'year' => (int) $matches['year'],
            'month' => isset($matches['month']) ? (int) $matches['month'] : null,
            'day' => isset($matches['day']) ? (int) $matches['day'] : null,
            'hour' => isset($matches['hour']) ? (int) $matches['hour'] : null,
            'minute' => isset($matches['minute']) ? (int) $matches['minute'] : null,
            'second' => isset($matches['second']) ? (int) $matches['second'] : null,
            'offset_hour' => isset($matches['offset_hour']) ? (int) $matches['offset_hour'] : null,
            'offset_minute' => isset($matches['offset_minute']) ? (int) $matches['offset_minute'] : null,
        ];

        // Set the normalized datetime components. Each component not included
        // in the passed value is given a default value.
        $dateTime['month_normalized'] = $dateTime['month'] ?? ($defaultFirst ? 1 : 12);
        // The last day takes special handling, as it depends on year/month.
        $dateTime['day_normalized'] = $dateTime['day']
            ?? ($defaultFirst ? 1 : $this->getLastDay($dateTime['year'], $dateTime['month_normalized']));
        $dateTime['hour_normalized'] = $dateTime['hour'] ?? ($defaultFirst ? 0 : 23);
        $dateTime['minute_normalized'] = $dateTime['minute'] ?? ($defaultFirst ? 0 : 59);
        $dateTime['second_normalized'] = $dateTime['second'] ?? ($defaultFirst ? 0 : 59);
        $dateTime['offset_hour_normalized'] = $dateTime['offset_hour'] ?? 0;
        $dateTime['offset_minute_normalized'] = $dateTime['offset_minute'] ?? 0;
        // Set the UTC offset (+00:00) if no offset is provided.
        $dateTime['offset_normalized'] = isset($dateTime['offset_value'])
            ? ('Z' === $dateTime['offset_value'] ? '+00:00' : $dateTime['offset_value'])
            : '+00:00';

        // Validate ranges of the datetime component.
        if (($this->yearMin > $dateTime['year']) || ($this->yearMax < $dateTime['year'])) {
            return null;
        }
        if ((1 > $dateTime['month_normalized']) || (12 < $dateTime['month_normalized'])) {
            return null;
        }
        if ((1 > $dateTime['day_normalized']) || (31 < $dateTime['day_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['hour_normalized']) || (23 < $dateTime['hour_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['minute_normalized']) || (59 < $dateTime['minute_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['second_normalized']) || (59 < $dateTime['second_normalized'])) {
            return null;
        }
        if ((-23 > $dateTime['offset_hour_normalized']) || (23 < $dateTime['offset_hour_normalized'])) {
            return null;
        }
        if ((0 > $dateTime['offset_minute_normalized']) || (59 < $dateTime['offset_minute_normalized'])) {
            return null;
        }

        // Set the ISO 8601 format.
        if (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\TH:i:sP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\TH:iP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\THP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second'])) {
            $format = 'Y-m-d\TH:i:s';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute'])) {
            $format = 'Y-m-d\TH:i';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour'])) {
            $format = 'Y-m-d\TH';
        } elseif (isset($dateTime['month']) && isset($dateTime['day'])) {
            $format = 'Y-m-d';
        } elseif (isset($dateTime['month'])) {
            $format = 'Y-m';
        } else {
            $format = 'Y';
        }
        $dateTime['format_iso8601'] = $format;

        // Set the render format.
        if (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H:i:s P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H:i P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second'])) {
            $format = 'F j, Y H:i:s';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute'])) {
            $format = 'F j, Y H:i';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour'])) {
            $format = 'F j, Y H';
        } elseif (isset($dateTime['month']) && isset($dateTime['day'])) {
            $format = 'F j, Y';
        } elseif (isset($dateTime['month'])) {
            $format = 'F Y';
        } else {
            $format = 'Y';
        }
        $dateTime['format_render'] = $format;

        // Adding the DateTime object here to reduce code duplication. To ensure
        // consistency, use Coordinated Universal Time (UTC) if no offset is
        // provided. This avoids automatic adjustments based on the server's
        // default timezone.
        // With strict type, "now" is required.
        $dateTime['date'] = new DateTime('now', new DateTimeZone($dateTime['offset_normalized']));
        $dateTime['date']->setDate(
            $dateTime['year'],
            $dateTime['month_normalized'],
            $dateTime['day_normalized']
        )->setTime(
            $dateTime['hour_normalized'],
            $dateTime['minute_normalized'],
            $dateTime['second_normalized']
        );

        if ($forSql) {
            return $dateTime['date']->format('Y-m-d H:i:s');
        }

        if ($formatted) {
            return $fullDatetime
                ? $dateTime['date']->format('Y-m-d\TH:i:s')
                : $dateTime['date']->format($dateTime['format_iso8601']);
        }

        return $dateTime['date'];
    }

    /**
     * Get the last day of a given year/month.
     */
    protected function getLastDay(int $year, int $month): int
    {
        switch ($month) {
            case 2:
                // February (accounting for leap year)
                $leapYear = date('L', mktime(0, 0, 0, 1, 1, $year));
                return $leapYear ? 29 : 28;
            case 4:
            case 6:
            case 9:
            case 11:
                // April, June, September, November
                return 30;
            default:
                // January, March, May, July, August, October, December
                return 31;
        }
    }
}
