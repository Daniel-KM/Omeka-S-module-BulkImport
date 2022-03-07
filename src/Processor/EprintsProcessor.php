<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\EprintsProcessorConfigForm;
use BulkImport\Form\Processor\EprintsProcessorParamsForm;
use BulkImport\Stdlib\MessageStore;

/**
 * @todo Use transformSource() instead hard coded mapping or create sql views.
 */
class EprintsProcessor extends AbstractFullProcessor
{
    use MetadataTransformTrait;

    protected $resourceLabel = 'Eprints'; // @translate
    protected $configFormClass = EprintsProcessorConfigForm::class;
    protected $paramsFormClass = EprintsProcessorParamsForm::class;

    protected $configDefault = [
        'endpoint' => null,
        'key_identity' => null,
        'key_credential' => null,
    ];

    protected $paramsDefault = [
        'o:owner' => null,
        'types' => [
            'users',
            'items',
            'media',
            // 'item_sets',
            'concepts',
            // 'hits',
        ],
        'fake_files' => true,
        'endpoint' => null,
        'url_path' => null,
        'language' => null,
        'language_2' => null,
    ];

    protected $modules = [
        'NumericDataTypes',
        'UserName',
        'UserProfile',
    ];

    protected $mapping = [
        // List of modified data (but not the value) : `user_items_fields`

        // + user_role => kept in a user setting for module User Profile.
        'users' => [
            'source' => 'user',
            'key_id' => 'userid',
            'key_email' => 'email',
            'key_name' => 'username',
        ],
        'items' => [
            'source' => 'eprint',
            'key_id' => 'eprintid',
            'filters' => [
                '`eprint_status` IN ("archive", "buffer")',
            ],
        ],
        // It may be simpler to use file then document, than document.
        // + file => technical data of original and derivative files.
        // For file: fetch only file that are document with an original filename
        // because other files are technical (xml data, frequent words,
        // thumbnails, etc.)
        'media' => [
            'source' => 'document',
            'key_id' => 'docid',
            'key_parent_id' => 'eprintid',
            'filters' => [
                // The join avoids to load media whose item is not imported.
                'JOIN `eprint` ON `eprint`.`eprintid` = `document`.`eprintid`',
                '`eprint`.`eprint_status` IN ("archive", "buffer")',
                // There is a filter on eprints, so use it here too.
                '`document`.`format` != "other"',
            ],
        ],
        // 'media_items' => [],
        // 'item_sets' => [],
        // 'values' => [],
        // Concepts are managed in 5 tables: "subject", "subject_ancestor",
        // "subject_name_lang", "subject_name_name", "subject_parents".
        'concepts' => [
            'source' => 'subject',
            'key_id' => 'subjectid',
            // The parent is stored in another table.
            // 'key_parent_id' => 'parent',
        ],
        // TODO Use a direct copy of the table, because it may be too much big.
        'hits' => [
            'source' => 'access',
            'key_id' => 'accessid',
        ],
    ];

    protected $main = [
        'concept' => [
            'template' => 'Thesaurus Concept',
            'class' => 'skos:Concept',
            'item' => null,
            'item_set' => null,
            'custom_vocab' => null,
        ],
        'templates' => [
            'Thesaurus Concept' => null,
            'Thesaurus Scheme' => null,
        ],
        'classes' => [
            'skos:Concept' => null,
            'skos:ConceptScheme' => null,
        ],
    ];

    // For internal use.
    protected $itemDirPaths = [];

    protected function preImport(): void
    {
        $total = $this->connection->executeQuery('SELECT count(`id`) FROM `resource`;')->fetchOne();
        if ($total) {
            $this->logger->warn(
                'Even if no issues were found during tests, it is recommenced to run this importer on a database without resources.' // @translate
            );
        }

        // No config to prepare currently.
        $this->prepareConfig('config.php', 'eprints');

        // With this processor, direct requests are done to the source database,
        // so check right of the current database user.

        if (!$this->reader->canReadDirectly()) {
            $this->hasError = true;
            $dbConfig = $this->reader->getDbConfig();
            $this->logger->err(
                'The Omeka database user should be able to read the source database, so run this query or a similar one with a database admin user: "{sql}".',  // @translate
                ['sql' => sprintf("GRANT SELECT ON `%s`.* TO '%s'@'%s';", $dbConfig['database'], $dbConfig['username'], $dbConfig['hostname'])]
            );
            $this->logger->err(
                'In some cases, the grants should be given to the omeka database user too.'  // @translate
            );
            return;
        }

        // TODO Remove these fixes.
        $args = $this->getParams();

        if (empty($args['types_selected'])) {
            $this->hasError = true;
            $this->logger->err(
                'The job cannot be restarted.' // @translate
            );
            return;
        }

        if (empty($args['types'])) {
            $this->hasError = true;
            $this->logger->err(
                'The job cannot be restarted. Restart import from the beginning.' // @translate
            );
            return;
        }

        // No special table to copy or prepare.

        if (!empty($this->tables)) {
            $this->logger->info(
                'Copying {total} tables from the source.',  // @translate
                ['total' => count($this->tables)]
                );
            foreach ($this->tables as $table) {
                $result = $this->copyTable($table);
                if (!$result) {
                    $this->hasError = true;
                    $this->logger->err(
                        'Unable to copy source table "{table}".',  // @translate
                        ['table' => $table]
                    );
                    return;
                }
            }
        }

        $this->prepareInternalVocabularies();
        $this->prepareInternalTemplates();
    }

    protected function prepareMedias(): void
    {
        $this->prepareResources($this->prepareReader('media'), 'media');
    }

    protected function prepareThesaurus(): void
    {
        // There may be multiple thesaurus.
        // So the first step is to list them, then do a loop with them.

        // A thesaurus is normally small (less than some thousands concepts),
        // so it is loaded as a whole to simplify process.

        $sourceType = 'concepts';

        $roots = $this->reader
            ->setFilters(['parents = "ROOT"'])
            ->setOrders([['by' => 'subjectid'], ['by' => 'pos'], ['by' => 'parents']])
            ->setObjectType('subject_parents')
            ->fetchAllKeyValues('subjectid');

        $ids = $this
            ->prepareReader('concepts')
            ->fetchAllKeyValues('subjectid');

        if (empty($roots)) {
            $roots = ['_root_concept_' => null];
            $ids = array_merge($roots, $ids);
        }

        // Keys starts from 1 to be sure that the created ids won't be 0.
        $this->map['concepts'] = array_fill_keys(array_keys($ids), null);

        /**
         * @var \Omeka\Entity\Vocabulary $vocabulary
         * @var \Omeka\Entity\ResourceClass $resourceClass
         * @var \Omeka\Entity\ResourceTemplate $resourceTemplate
         */
        $vocabulary = $this->entityManager->getRepository(\Omeka\Entity\Vocabulary::class)->findOneBy(['prefix' => 'skos']);
        $class = $this->entityManager->getRepository(\Omeka\Entity\ResourceClass::class)->findOneBy(['vocabulary' => $vocabulary, 'localName' => 'Concept']);
        $template = $this->entityManager->getRepository(\Omeka\Entity\ResourceTemplate::class)->findOneBy(['label' => 'Thesaurus Concept']);

        // Prepare the resources for all the ids.
        $resourceColumns = [
            'id' => 'id',
            'owner_id' => $this->owner ? $this->ownerId : 'NULL',
            'resource_class_id' => $class ? $class->getId() : 'NULL',
            'resource_template_id' => $template ? $template->getId() : 'NULL',
            'is_public' => '0',
            'created' => '"' . $this->currentDateTimeFormatted . '"',
            'modified' => 'NULL',
            'resource_type' => $this->connection->quote($this->importables[$sourceType]['class']),
            'thumbnail_id' => 'NULL',
            'title' => 'id',
        ];
        $this->createEmptyEntities($sourceType, $resourceColumns, false, true, true);
        $this->createEmptyResourcesSpecific($sourceType);

        $this->rootConcepts = [];
        foreach (array_keys($roots) as $schemeSourceId) {
            $this->rootConcepts[$schemeSourceId] = $this->map['concepts'][$schemeSourceId];
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        // Prepare the schemes, item set and custom vocabs..
        $this->thesaurusConfigs = [];
        foreach ($this->rootConcepts as $sourceId => $destinationId) {
            // Fill the thesaurus scheme.
            $this->configThesaurus = $sourceId;
            $this->main['concepts_' . $sourceId] = [
                'template' => 'Thesaurus Concept',
                'class' => 'skos:Concept',
                'item' => null,
                'item_set' => null,
                'custom_vocab' => null,
            ];
            $this->thesaurusConfigs[$sourceId] = [
                // If resources are already created.
                'resources_ready' => [
                    'scheme' => $destinationId,
                    'item_set' => null,
                    'custom_vocab' => null,
                ],
                // New thesaurus.
                'label' => 'Thesaurus ' . $sourceId,
                'mapping_name' => 'concepts_' . $sourceId,
                'main_name' => 'concepts_' . $sourceId,
                // Data from the source.
                'source' => 'subject',
                'key_id' => 'subjectid',
                'key_parent_id' => null,
                'key_label' => null,
                'key_definition' => null,
                'key_scope_note' => null,
                'key_created' => null,
                'key_modified' => null,
                'narrowers_sort' => null,
            ];
            parent::prepareThesaurus();
        }

        $this->configThesaurus = 'concepts';
    }

    protected function prepareConcepts(iterable $sources): void
    {
        // Skip parent.
    }

    protected function fillUser(array $source, array $user): array
    {
        // Note: everything is string or null from the sql reader.
        /*
        userid    int(11)
        rev_number    int(11) NULL
        username    varchar(255) NULL
        password    varchar(255) NULL
        usertype    varchar(255) NULL
        newemail    varchar(255) NULL
        newpassword    varchar(255) NULL
        pin    varchar(255) NULL
        pinsettime    int(11) NULL
        joined_year    smallint(6) NULL
        joined_month    smallint(6) NULL
        joined_day    smallint(6) NULL
        joined_hour    smallint(6) NULL
        joined_minute    smallint(6) NULL
        joined_second    smallint(6) NULL
        email    varchar(255) NULL
        lang    varchar(255) NULL
        frequency    varchar(255) NULL
        mailempty    varchar(5) NULL
        latitude    float NULL
        longitude    float NULL
        preference    blob NULL
        name_family    varchar(64) NULL
        name_given    varchar(64) NULL
        name_lineage    varchar(10) NULL
        name_honourific    varchar(10) NULL
        dept    varchar(255) NULL
        org    varchar(255) NULL
        address    longtext NULL
        country    varchar(255) NULL
        hideemail    varchar(5) NULL
        url    longtext NULL
        student_id    int(11) NULL
         */

        $mapRoles = [
            'admin' => \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            'editor' => \Omeka\Permissions\Acl::ROLE_EDITOR,
            'user' => empty($this->modules['Guest']) ? 'researcher' : 'guest',
        ];

        $locale = 'fr';

        $isActive = true;

        // Get the mapped data in case of fixed duplicates.
        $userName = $user['o-module-usernames:username'];

        // Omeka name is not required to be unique, so use a prettier name.
        $oName = trim($source['name_given'] . ' ' . $source['name_family']) ?: $userName;

        $created = $this->implodeDate(
            $source['joined_year'],
            $source['joined_month'],
            $source['joined_day'],
            $source['joined_hour'],
            $source['joined_minute'],
            $source['joined_second'],
            $source['pinsettime'],
            true,
            true,
            true
        ) ?: $this->currentDateTimeFormatted;

        // Not recorded in eprints, but the revision number.
        $modified = null;

        $role = $mapRoles[$source['usertype']] ?? 'guest';

        return array_replace($user, [
            'o:name' => $oName,
            'o:created' => [
                '@value' => $created,
            ],
            'o:modified' => $modified,
            'o:role' => $role,
            'o:is_active' => $isActive,
            'o:settings' => [
                'locale' => $source['lang'] ?: $locale,
                'userprofile_rev' => $source['rev_number'],
                // May be "daily", "weekly" or "never".
                'userprofile_frequency' => $source['frequency'],
                // Mail empty is a useless boolean (check contact messages).
                'userprofile_latitude' => $source['latitude'],
                'userprofile_longitude' => $source['longitude'],
                // Preference: a blob that is not imported.
                'userprofile_name_family' => $source['name_family'],
                'userprofile_name_given' => $source['name_given'],
                'userprofile_name_lineage' => $source['name_lineage'],
                'userprofile_name_honourific' => $source['name_honourific'],
                'userprofile_departement' => $source['dept'],
                'userprofile_organisation' => $source['org'],
                'userprofile_address' => $source['address'],
                'userprofile_country' => $source['country'],
                'userprofile_hide_email' => $source['hideemail'] === true || strtolower($source['hideemail']) === 'true',
                'userprofile_url' => $source['url'],
                'userprofile_student_id' => $source['student_id'] ?: '',
            ],
        ]);
    }

    protected function fillItem(array $source): void
    {
        // Note: everything is string or null from the sql reader.
        /*
        eprintid    int(11)
        rev_number    int(11) NULL
        eprint_status    varchar(255) NULL
        userid    int(11) NULL
        importid    int(11) NULL
        source    varchar(255) NULL
        dir    varchar(255) NULL
        datestamp_year    smallint(6) NULL
        datestamp_month    smallint(6) NULL
        datestamp_day    smallint(6) NULL
        datestamp_hour    smallint(6) NULL
        datestamp_minute    smallint(6) NULL
        datestamp_second    smallint(6) NULL
        lastmod_year    smallint(6) NULL
        lastmod_month    smallint(6) NULL
        lastmod_day    smallint(6) NULL
        lastmod_hour    smallint(6) NULL
        lastmod_minute    smallint(6) NULL
        lastmod_second    smallint(6) NULL
        status_changed_year    smallint(6) NULL
        status_changed_month    smallint(6) NULL
        status_changed_day    smallint(6) NULL
        status_changed_hour    smallint(6) NULL
        status_changed_minute    smallint(6) NULL
        status_changed_second    smallint(6) NULL
        type    varchar(255) NULL
        succeeds    int(11) NULL
        commentary    int(11) NULL
        metadata_visibility    varchar(255) NULL
        contact_email    varchar(255) NULL
        fileinfo    longtext NULL
        latitude    float NULL
        longitude    float NULL
        item_issues_count    int(11) NULL
        sword_depositor    int(11) NULL
        sword_slug    varchar(255) NULL
        edit_lock_user    int(11) NULL
        edit_lock_since    int(11) NULL
        edit_lock_until    int(11) NULL
        title    longtext NULL
        ispublished    varchar(255) NULL
        full_text_status    varchar(255) NULL
        monograph_type    varchar(255) NULL
        pres_type    varchar(255) NULL
        keywords    longtext NULL
        note    longtext NULL
        suggestions    longtext NULL
        abstract    longtext NULL
        date_year    smallint(6) NULL
        date_month    smallint(6) NULL
        date_day    smallint(6) NULL
        date_type    varchar(255) NULL
        series    varchar(255) NULL
        publication    varchar(255) NULL
        volume    varchar(6) NULL
        number    varchar(6) NULL
        publisher    varchar(255) NULL
        place_of_pub    varchar(255) NULL
        pagerange    varchar(255) NULL
        pages    int(11) NULL
        event_title    varchar(255) NULL
        event_location    varchar(255) NULL
        event_dates    varchar(255) NULL
        event_type    varchar(255) NULL
        id_number    varchar(255) NULL
        patent_applicant    varchar(255) NULL
        institution    varchar(255) NULL
        department    varchar(255) NULL
        thesis_type    varchar(255) NULL
        refereed    varchar(5) NULL
        isbn    varchar(255) NULL
        issn    varchar(255) NULL
        book_title    varchar(255) NULL
        official_url    longtext NULL
        referencetext    longtext NULL
        output_media    varchar(255) NULL
        num_pieces    int(11) NULL
        composition_type    varchar(255) NULL
        data_type    varchar(255) NULL
        pedagogic_type    varchar(255) NULL
        completion_time    varchar(255) NULL
        task_purpose    longtext NULL
        learning_level    varchar(255) NULL
        gscholar_impact    int(11) NULL
        gscholar_cluster    varchar(255) NULL
        gscholar_datestamp_year    smallint(6) NULL
        gscholar_datestamp_month    smallint(6) NULL
        gscholar_datestamp_day    smallint(6) NULL
        gscholar_datestamp_hour    smallint(6) NULL
        gscholar_datestamp_minute    smallint(6) NULL
        gscholar_datestamp_second    smallint(6) NULL
        people    varchar(255) NULL
        degrees    varchar(255) NULL
        director_family    varchar(64) NULL
        director_given    varchar(64) NULL
        director_lineage    varchar(10) NULL
        director_honourific    varchar(10) NULL
        abstract_other    longtext NULL
        master_type    varchar(255) NULL
        keywords_other    longtext NULL
        directors_other_family    varchar(64) NULL
        directors_other_given    varchar(64) NULL
        directors_other_lineage    varchar(10) NULL
        directors_other_honourific    varchar(10) NULL
        title_other    longtext
        thesis_number    varchar(255)
        hidden    varchar(5)
        title_english    longtext
        institution_partner    mediumtext
        doctoral_school    varchar(255)
        abstract_english    longtext
        keywords_english    longtext NULL
         */

        $source = array_map('trim', array_map('strval', $source));

        // The key source id is already checked in prepareResources().
        $keySourceId = $this->mapping['items']['key_id'] ?? 'id';
        $sourceId = $source[$keySourceId];

        // Create a json-ld resource.

        // See some documentation on https://wiki.eprints.org.
        // @link https://wiki.eprints.org/w/Eprint_fields_pub.pl
        // Some values are related to eprints plugins, but empty.

        // "full_text_status", "ispublished" and "hidden" are not related.
        $isPublic = !in_array($source['ispublished'], ['unpub', 'submitted', 'inpress']);

        $item = [
            // Keep the source id to simplify next steps and find mapped id.
            // The source id may be updated if duplicate.
            '_source_id' => $sourceId,
            '@type' => [
                'o:Item',
            ],
            'o:id' => $this->map['items'][$sourceId],
            // @link https://wiki.eprints.org/w/index.php/Views.pl.
            'o:is_public' => $isPublic,
            // And tables eprint_creator_id and eprint_creator_name are the same.
            'o:owner' => empty($source['userid'])
                ? null
                : ['o:id' => $source['userid']],
            'o:resource_class' => null,
            'o:resource_template' => null,
            'o:title' => null,
            'o:created' => null,
            'o:modified' => null,
            'o:media' => null,
            'o:item_sets' => null,
        ];

        $type = $source['type'];

        $template = $this->configs['types_to_resource_templates'][$type] ?? null;
        if ($template) {
            $templateId = $this->map['resource_templates'][$template] ?? null;
            if ($templateId) {
                $item['o:resource_template'] = ['o:id' => $templateId];
            } else {
                // No stop.
                $this->logger->err(
                    'No resource template for {resource_template}.', // @translate
                    ['resource_template' => $template]
                );
            }
            // TODO Add default class and values of the template (advanced resource template).
        }

        $class = $this->configs['types_to_resource_classes'][$type] ?? null;
        if ($class) {
            // Warning: members of vocabularies use "id". A "by-id" exist too.
            $classId = $this->map['resource_classes'][$class]['id'] ?? null;
            if ($classId) {
                $item['@type'][] = $class;
                $item['o:resource_class'] = ['o:id' => $classId];
            } else {
                // Don't stop.
                $this->logger->err(
                    'No resource class for {resource_class}.', // @translate
                    ['resource_class' => $class]
                );
            }
        }

        $created = $this->implodeDate(
            $source['datestamp_year'],
            $source['datestamp_month'],
            $source['datestamp_day'],
            $source['datestamp_hour'],
            $source['datestamp_minute'],
            $source['datestamp_second'],
            null,
            true,
            true,
            true
        ) ?: $this->currentDateTimeFormatted;

        $item['o:created'] = ['@value' => $created];

        $modified = $this->implodeDate(
            $source['lastmod_year'],
            $source['lastmod_month'],
            $source['lastmod_day'],
            $source['lastmod_hour'],
            $source['lastmod_minute'],
            $source['lastmod_second'],
            null,
            true,
            true,
            true
        );
        if (!$modified && $source['rev_number'] > 1) {
            $modified = $this->currentDateTimeFormatted;
        }

        $item['o:modified'] = $modified ? ['@value' => $modified] : null;

        // Use the short values mechanism.
        // Note: the mapping is flat here, even if ideally, directors, events,
        // etc. should be created separately and linked.

        $values = [];

        if ((int) $source['rev_number']) {
            $values[] = [
                'term' => 'dante:numeroRevision',
                'type' => 'numeric:integer',
                'value' => (int) $source['rev_number'],
                'is_public' => false,
            ];
        }

        $values[] = [
            'term' => 'bibo:status',
            'type' => $this->configs['custom_vocabs']['eprint_status'] ?? 'literal',
            'value' => $source['eprint_status'],
            'is_public' => false,
        ];

        // Import id is not kept. Anyway always null.

        // Source: always empty.

        // Dir: useless, managed with media files.

        // Dates are stored as value too.

        // TODO Warning: some created are not set, but only modified…

        $created = $this->implodeDate(
            $source['datestamp_year'],
            $source['datestamp_month'],
            $source['datestamp_day'],
            $source['datestamp_hour'],
            $source['datestamp_minute'],
            $source['datestamp_second'],
            null,
            true,
            false,
            false
        ) ?: $this->currentDateTimeFormatted;
        $values[] = [
            'term' => 'dcterms:created',
            'type' => 'numeric:timestamp',
            'value' => $created,
        ];

        $modified = $this->implodeDate(
            $source['lastmod_year'],
            $source['lastmod_month'],
            $source['lastmod_day'],
            $source['lastmod_hour'],
            $source['lastmod_minute'],
            $source['lastmod_second'],
            null,
            true,
            false,
            false
        );
        if ($modified && $source['rev_number'] > 1) {
            $values[] = [
                'term' => 'dcterms:modified',
                'type' => 'numeric:timestamp',
                'value' => $modified,
            ];
        }

        // Useless in Omeka for now.
        /*
        $statusChanged = $this->implodeDate(
            $source['status_changed_year'],
            $source['status_changed_month'],
            $source['status_changed_day'],
            $source['status_changed_hour'],
            $source['status_changed_minute'],
            $source['status_changed_second'],
            null,
            true,
            false,
            false
        );
        if ($statusChanged) {
            $values[] = [
                'term' => 'dcterms:date',
                'value' => $statusChanged,
                'type' => 'numeric:timestamp',
            ];
        }
        */

        // Succeeds: eprints internal data.

        // Commentary is a numeric value.
        if ($source['commentary']) {
            $values[] = [
                'term' => 'curation:data',
                'value' => 'Commentaire : ' . $source['commentary'],
                // 'type' => 'numeric:integer',
                'is_public' => false,
            ];
        }

        if ($source['metadata_visibility']) {
            $values[] = [
                'term' => 'dante:affichageMetadonnees',
                'value' => $source['metadata_visibility'],
                'is_public' => false,
            ];
        }

        if ($source['contact_email']) {
            $values[] = [
                'term' => 'foaf:mbox',
                'value' => $source['contact_email'],
                'is_public' => false,
            ];
        }

        // File info : Useless here.
        // File info is a list of file separated with ";".
        // $source['fileinfo'];

        if ($source['latitude'] && $source['longitude']) {
            $values[] = [
                'term' => 'dcterms:spatial',
                'value' => 'Latitude : ' . $source['latitude'] . ' / Longitude : ' . $source['longitude'],
                'is_public' => false,
            ];
        }

        // Item issues count: useless.

        // TODO SWORD (Simple Web-service Offering Repository Deposit) protocol.

        // Lock data: useless (edit_lock_user, edit_lock_since, edit_lock_until).

        if ($source['title']) {
            $item['o:title'] = $source['title'];
            $values[] = [
                'term' => 'dcterms:title',
                'lang' => 'fra',
                'value' => $source['title'],
            ];
        }

        // Is published.
        if ($source['ispublished']) {
            $values[] = [
                'term' => 'bibo:status',
                // TODO Use code: "unpub", "submitted", "inpress".
                'type' => $this->configs['custom_vocabs']['ispublished'] ?? 'literal',
                'value' => $source['ispublished'],
                'is_public' => false,
            ];
        }

        if ($source['full_text_status']) {
            $values[] = [
                'term' => 'curation:status',
                'type' => $this->configs['custom_vocabs']['full_text_status'] ?? 'literal',
                'value' => $source['full_text_status'],
                'is_public' => false,
            ];
        }

        if ($source['monograph_type']) {
            $values[] = [
                'term' => 'dcterms:type',
                'type' => $this->configs['custom_vocabs']['monograph_type'] ?? 'literal',
                'value' => $source['monograph_type'],
            ];
        }

        if ($source['pres_type']) {
            $values[] = [
                'term' => 'dcterms:type',
                'type' => $this->configs['custom_vocabs']['pres_type'] ?? 'literal',
                'value' => $source['pres_type'],
            ];
        }

        // The separator is not defined, so use the ones found.
        $list = array_filter(array_map('trim', explode("\n", str_replace([',', ';', "\n"], ["\n", "\n", "\n"], (string) $source['keywords']))));
        foreach ($list as $keyword) {
            $values[] = [
                'term' => 'dcterms:subject',
                'lang' => 'fra',
                'value' => $keyword,
            ];
        }

        // TODO What is the difference between notes and suggestions?
        if ($source['note']) {
            $values[] = [
                'term' => 'curation:note',
                'value' => $source['note'],
                'is_public' => false,
            ];
        }

        if ($source['suggestions']) {
            $values[] = [
                'term' => 'bibo:annotates',
                'value' => $source['suggestions'],
                'is_public' => false,
            ];
        }

        if ($source['abstract']) {
            $values[] = [
                'term' => 'dcterms:abstract',
                'lang' => 'fra',
                'value' => $source['abstract'],
            ];
        }

        // Another date.
        $date = $this->implodeDate(
            $source['date_year'],
            $source['date_month'],
            $source['date_day'],
            null,
            null,
            null,
            null,
            true,
            false,
            false
        );
        if ($date) {
            // There is only one date type in the database, "published".
            $dateTypes = [
                'published' => 'dcterms:issued',
            ];
            if ($source['date_type'] && !isset($source['date_type'])) {
                $this->logger->warn(
                    'The date type "{type}" is not mapped.', // @translate
                    ['type' => $source['date_type']]
                );
            }
            $values[] = [
                'term' => $dateTypes[$source['date_type']] ?? 'dcterms:date',
                'value' => $source['abstract'],
            ];
        }

        // For articles.
        if ($source['series']) {
            $values[] = [
                'term' => 'dcterms:isPartOf',
                'value' => $source['series'],
            ];
        }

        if ($source['publication']) {
            $values[] = [
                'term' => 'bibo:issue',
                'value' => $source['publication'],
            ];
        }

        if ($source['volume']) {
            $values[] = [
                'term' => 'bibo:volume',
                'value' => $source['volume'],
            ];
        }

        if ($source['number']) {
            $values[] = [
                'term' => 'bibo:number',
                'value' => $source['number'],
            ];
        }

        if ($source['publisher']) {
            $values[] = [
                'term' => 'dcterms:publisher',
                'value' => $source['publisher'],
            ];
        }

        if ($source['place_of_pub']) {
            $values[] = [
                'term' => 'bio:place',
                'value' => $source['place_of_pub'],
            ];
        }

        if ($source['pagerange']) {
            $values[] = [
                'term' => 'bibo:pages',
                'value' => $source['pagerange'],
            ];
        }

        if ($source['pages']) {
            $values[] = [
                'term' => 'bibo:numPages',
                'type' => 'numeric:integer',
                'value' => $source['pages'],
            ];
        }

        // For event, a linked resource should be created.

        if ($source['event_title']) {
            $values[] = [
                'term' => 'bibo:presentedAt',
                'value' => $source['event_title'],
            ];
        }

        if ($source['event_location']) {
            $values[] = [
                'term' => 'curation:location',
                'value' => $source['event_location'],
            ];
        }

        if ($source['event_dates']) {
            $values[] = [
                'term' => 'bio:date',
                'value' => $source['event_dates'],
            ];
        }

        if ($source['event_type']) {
            $values[] = [
                'term' => 'curation:category',
                'value' => $source['event_type'],
            ];
        }

        if ($source['id_number']) {
            $values[] = [
                'term' => 'dcterms:identifier',
                'value' => $source['id_number'],
            ];
        }

        if ($source['patent_applicant']) {
            $values[] = [
                'term' => 'curation:data',
                'value' => 'Patent applicant : ' . $source['patent_applicant'],
            ];
        }

        if ($source['institution']) {
            $values[] = [
                'term' => 'dante:etablissement',
                'value' => $source['institution'],
            ];
        }

        if ($source['department']) {
            if ($template === 'Thèse') {
                $values[] = [
                    'term' => 'dante:ecoleDoctorale',
                    'type' => $this->configs['custom_vocabs']['doctoral_school'] ?? 'literal',
                    'value' => $source['department'],
                ];
            } else {
                $values[] = [
                    'term' => 'dante:ufrOuComposante',
                    'type' => $this->configs['custom_vocabs']['divisions'] ?? 'literal',
                    'value' => $source['department'],
                ];
            }
        }

        if ($source['thesis_type']) {
            $values[] = [
                'term' => 'curation:type',
                'type' => $this->configs['custom_vocabs']['thesis_type'] ?? 'literal',
                'value' => $source['thesis_type'],
            ];
        }

        // Short string for a bool.
        if ($source['refereed']) {
            $values[] = [
                'term' => 'curation:data',
                // 'type' => 'boolean',
                'value' => sprintf('Arbitré : %s', strtolower($source['refereed']) === 'true' ? 'oui' : 'non'),
            ];
        }

        if ($source['isbn']) {
            $values[] = [
                // TODO Use isbn 10/13 too.
                'term' => 'bibo:isbn',
                'value' => $source['isbn'],
            ];
        }

        if ($source['issn']) {
            $values[] = [
                'term' => 'bibo:issn',
                'value' => $source['issn'],
            ];
        }

        // Should be a related resource (like event or publication).
        if ($source['book_title']) {
            $values[] = [
                'term' => 'dcterms:isPartOf',
                'value' => $source['book_title'],
            ];
        }

        if ($source['official_url']) {
            $values[] = [
                'term' => 'bibo:url',
                'type' => 'uri',
                'value' => $source['official_url'],
            ];
        }

        // Apparently may be the extracted content.
        if ($source['referencetext']) {
            $values[] = [
                'term' => 'bibo:content',
                'value' => $source['referencetext'],
            ];
        }

        // TODO What is output_media?
        if ($source['output_media']) {
            $values[] = [
                'term' => '',
                'value' => $source['output_media'],
            ];
        }

        if ($source['num_pieces']) {
            $values[] = [
                'term' => 'bibo:numVolumes',
                'type' => 'numeric:integer',
                'value' => $source['num_pieces'],
            ];
        }

        // Next values are related to learning objects.
        // TODO Use the LOM ontology.

        if ($source['composition_type']) {
            $values[] = [
                'term' => '',
                'value' => $source['composition_type'],
            ];
        }

        if ($source['data_type']) {
            $values[] = [
                'term' => '',
                'value' => $source['data_type'],
            ];
        }

        if ($source['pedagogic_type']) {
            $values[] = [
                'term' => '',
                'value' => $source['pedagogic_type'],
            ];
        }

        if ($source['completion_time']) {
            $values[] = [
                'term' => '',
                'value' => $source['completion_time'],
            ];
        }

        if ($source['task_purpose']) {
            $values[] = [
                'term' => '',
                'value' => $source['task_purpose'],
            ];
        }

        if ($source['learning_level']) {
            $values[] = [
                'term' => '',
                'value' => $source['learning_level'],
            ];
        }

        if ($source['gscholar_impact']) {
            $values[] = [
                'term' => 'curation:rank',
                'type' => 'numeric:integer',
                'value' => $source['gscholar_impact'],
            ];
        }

        if ($source['gscholar_cluster']) {
            $values[] = [
                'term' => 'curation:collection',
                'value' => $source['gscholar_cluster'],
            ];
        }

        $value = $this->implodeDate(
            $source['gscholar_datestamp_year'],
            $source['gscholar_datestamp_month'],
            $source['gscholar_datestamp_day'],
            $source['gscholar_datestamp_hour'],
            $source['gscholar_datestamp_minute'],
            $source['gscholar_datestamp_second'],
            null,
            true,
            false,
            false
        );
        if ($value) {
            $values[] = [
                'term' => 'bibo:argued',
                'type' => 'numeric:timestamp',
                'value' => $value,
            ];
        }

        // What is people?
        if ($source['people']) {
            $values[] = [
                'term' => '',
                'value' => $source['people'],
            ];
        }

        if ($source['degrees']) {
            $values[] = [
                'term' => 'bibo:degree',
                'value' => $source['degrees'],
            ];
        }

        if ($source['director_family']) {
            $values[] = [
                'term' => 'dante:directeur',
                'value' => $source['director_family'],
            ];
        }

        if ($source['director_given']) {
            $values[] = [
                'term' => 'dante:directeur',
                'value' => $source['director_given'],
            ];
        }

        if ($source['director_lineage']) {
            $values[] = [
                'term' => 'dante:directeur',
                'value' => $source['director_lineage'],
            ];
        }

        if ($source['director_honourific']) {
            $values[] = [
                'term' => 'dante:directeur',
                'value' => $source['director_honourific'],
            ];
        }

        if ($source['abstract_other']) {
            $values[] = [
                'term' => 'bibo:abstract',
                'value' => $source['abstract_other'],
            ];
        }

        if ($source['master_type']) {
            $values[] = [
                'term' => 'dante:typeDiplome',
                'value' => $source['master_type'],
            ];
        }

        if ($source['keywords_other']) {
            $values[] = [
                'term' => 'dcterms:subject',
                // TODO Add the language of other keywords.
                'value' => $source['keywords_other'],
            ];
        }

        if ($source['directors_other_family']) {
            $values[] = [
                'term' => 'dante:codirecteur',
                'value' => $source['directors_other_family'],
            ];
        }

        if ($source['directors_other_given']) {
            $values[] = [
                'term' => 'dante:codirecteur',
                'value' => $source['directors_other_given'],
            ];
        }

        if ($source['directors_other_lineage']) {
            $values[] = [
                'term' => 'dante:codirecteur',
                'value' => $source['directors_other_lineage'],
            ];
        }

        if ($source['directors_other_honourific']) {
            $values[] = [
                'term' => 'dante:codirecteur',
                'value' => $source['directors_other_honourific'],
            ];
        }

        if ($source['title_other']) {
            $values[] = [
                'term' => 'dcterms:alternative',
                'value' => $source['title_other'],
            ];
        }

        if ($source['thesis_number']) {
            $values[] = [
                'term' => 'bibo:identifier',
                'value' => $source['thesis_number'],
            ];
        }

        if ($source['hidden']) {
            $values[] = [
                'term' => 'curation:data',
                // 'type' => 'boolean',
                'value' => sprintf('Hidden : %s', strtolower($source['hidden']) === 'true' ? 'oui' : 'non'),
                'is_public' => false,
            ];
        }

        if ($source['title_english']) {
            $values[] = [
                'term' => 'dcterms:title',
                'lang' => 'eng',
                'value' => $source['title_english'],
            ];
        }

        if ($source['institution_partner']) {
            $values[] = [
                'term' => 'dante:etablissementCotutelle',
                'value' => $source['institution_partner'],
            ];
        }

        if ($source['doctoral_school']) {
            $values[] = [
                'term' => 'dante:ecoleDoctorale',
                'value' => $source['doctoral_school'],
            ];
        }

        if ($source['abstract_english']) {
            $values[] = [
                'term' => 'dcterms:abstract',
                'lang' => 'eng',
                'value' => $source['abstract_english'],
            ];
        }

        $list = array_filter(array_map('trim', explode("\n", str_replace([',', ';', "\n"], ["\n", "\n", "\n"], (string) $source['keywords_english']))));
        foreach ($list as $keyword) {
            $values[] = [
                'term' => 'dcterms:subject',
                'lang' => 'eng',
                'value' => $keyword,
            ];
        }

        parent::fillItem($item);

        // Source is the current resource (this entity).
        $this->orderAndAppendValues($values);

        // To simplify next steps, store the dir path.

        $this->itemDirPaths[$sourceId] = trim($source['dir'], '/');
    }

    protected function fillMedia(array $source): void
    {
        /*
        docid    int(11)
        rev_number    int(11) NULL
        eprintid    int(11) NULL
        pos    int(11) NULL
        placement    int(11) NULL
        mime_type    varchar(255) NULL
        format    varchar(255) NULL
        formatdesc    varchar(255) NULL
        language    varchar(255) NULL
        security    varchar(255) NULL
        license    varchar(255) NULL
        main    varchar(255) NULL
        date_embargo_year    smallint(6) NULL
        date_embargo_month    smallint(6) NULL
        date_embargo_day    smallint(6) NULL
        content    varchar(255) NULL
        media_duration    varchar(255) NULL
        media_audio_codec    varchar(255) NULL
        media_video_codec    varchar(255) NULL
        media_width    int(11) NULL
        media_height    int(11) NULL
        media_aspect_ratio    varchar(255) NULL
        media_sample_start    varchar(255) NULL
        media_sample_stop    varchar(255) NULL
        page_number    int(11) NULL
        diff_permission    varchar(5)
        file_version    varchar(255) NULL
        dumas    varchar(5)
         */

        $source = array_map('trim', array_map('strval', $source));

        // The source id and the item id are already checked in prepareResources().

        $keySourceId = $this->mapping['media']['key_id'] ?? 'id';
        $sourceId = $source[$keySourceId];

        $keyItemId = $this->mapping['media']['key_parent_id'] ?? null;
        if (empty($source[$keyItemId])) {
            $this->logger->warn(
                'There is no item key identifier for media source #{index}.', // @translate
                ['index' => $sourceId]
            );
            return;
        }

        $sourceItemId = $source[$keyItemId];
        $itemId = $this->map['items'][$sourceItemId] ?? null;
        if (empty($itemId)) {
            $this->logger->warn(
                'The item source #{indexitem} for media source #{index} does not exist.', // @translate
                ['indexitem' => $sourceItemId, 'index' => $sourceId]
            );
            return;
        }

        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $itemId);
        $ownerId = $item->getOwner() ? $item->getOwner()->getId() : null;

        // @see \Omeka\File\TempFile::getStorageId()
        $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
        $extension = basename($source['main']);
        $filename = $storageId . '.' . $extension;
        $endpoint = rtrim($this->getParam('url_path'), '/ ');
        $sourceBasename = '/' . $this->itemDirPaths[$sourceItemId]
            // TODO Check file or document.
            . '/' . $source['main'];;
        $sourceFile = $endpoint . $sourceBasename;
        $isUrl = $this->bulk->isUrl($sourceFile);

        // TODO Is public = security + diff_permission?
        $isPublic = $source['security'] === 'public';
        // $isRestricted = $source['security'] === 'validuser';

        // TODO Use table file to get created date of the document.
        // $created =

        $media = [
            // Keep the source id to simplify next steps and find mapped id.
            // The source id may be updated if duplicate.
            '_source_id' => $sourceId,
            '@type' => [
                'o:Media',
            ],
            'o:id' => $this->map['media'][$sourceId],
            'o:is_public' => $isPublic,
            'o:owner' => $ownerId ? ['o:id' => $ownerId] : null,
            'o:resource_class' => null,
            'o:resource_template' => null,
            'o:title' => null,
            'o:created' => $item->getCreated(),
            'o:modified' => null,
            'o:item' => ['o:id' => $this->map['items'][$sourceItemId]],
            // Source filename may be duplicate.
            'o:source' => $source['main'],
            'o:media_type' => $source['mime_type'] ?: null,
            'o:sha256' => null,
            'o:size' => null,
            'o:filename' => $filename,
            'o:lang' => $source['lang'] ?: null,
            'o:alt_text' => null,
            'o:ingester' => $isUrl ? 'url' : 'sideload',
            'o:renderer' => 'file',
        ];

        // Use the short values mechanism.

        $values = [];

        if ((int) $source['rev_number']) {
            $values[] = [
                'term' => 'dante:numeroRevision',
                'type' => 'numeric:integer',
                'value' => (int) $source['rev_number'],
                'is_public' => false,
            ];
        }

        if ($source['format']) {
            $values[] = [
                'term' => 'dcterms:format',
                'value' => $source['format'],
            ];
        }

        if ($source['formatdesc']) {
            $values[] = [
                'term' => 'dcterms:format',
                'value' => $source['formatdesc'],
            ];
        }

        if ($source['language']) {
            $values[] = [
                'term' => 'dcterms:language',
                'value' => $source['language'],
            ];
        }

        if ($source['security'] === 'validuser') {
            $values[] = [
                'term' => 'curation:reserved',
                'value' => 'Utilisateur identifié',
            ];
        }

        if ($source['license']) {
            $values[] = [
                'term' => 'dcterms:license',
                'value' => $source['license'],
            ];
        }

        if ($source['main']) {
            $values[] = [
                'term' => 'dcterms:title',
                'value' => $source['main'],
            ];
        }

        $value = $this->implodeDate(
            $source['date_embargo_year'],
            $source['date_embargo_month'],
            $source['date_embargo_day'],
            null,
            null,
            null,
            null,
            true,
            false,
            false
        );
        if ($value) {
            $values[] = [
                'term' => 'curation:dateEnd',
                'value' => $value,
                'type' => 'numeric:timestamp',
            ];
        }

        // Not the content in fact.
        if ($source['content']) {
            $values[] = [
                'term' => 'dcterms:type',
                'value' => $source['content'],
            ];
        }

        if ($source['media_duration']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_duration'],
            ];
        }

        if ($source['media_audio_codec']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_audio_codec'],
            ];
        }

        if ($source['media_video_codec']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_video_codec'],
            ];
        }

        if ($source['media_width']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_width'],
            ];
        }

        if ($source['media_height']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_height'],
            ];
        }

        if ($source['media_aspect_ratio']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_aspect_ratio'],
            ];
        }

        if ($source['media_sample_start']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_sample_start'],
            ];
        }

        if ($source['media_sample_stop']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_sample_stop'],
            ];
        }

        if ($source['media_sample_start']) {
            $values[] = [
                'term' => '',
                'value' => $source['media_sample_start'],
            ];
        }

        if ($source['page_number']) {
            $values[] = [
                'term' => 'bibo:pages',
                'value' => $source['page_number'],
            ];
        }

        if ($source['diff_permission']) {
            $values[] = [
                'term' => 'dcterms:accessRights',
                'type' => 'boolean',
                'value' => strtolower($source['diff_permission']) === 'true' ? '1' : '0',
            ];
        }

        if ($source['file_version']) {
            $values[] = [
                'term' => 'dante:version',
                'value' => $source['file_version'],
            ];
        }

        if ($source['dumas']) {
            $values[] = [
                'term' => 'dante:diffusionDumas',
                'type' => 'boolean',
                'value' => strtolower($source['dumas']) === 'true' ? '1' : '0',
            ];
        }

        parent::fillMedia($media);

        // TODO What is the real difference between "pos" and "placement"?
        // Note: the position is set automatically in parent method according to
        // the order of media item.
        if ((int) $source['pos']) {
            $this->entity->setPosition((int) $source['pos']);
        }

        // Source is the current resource (this entity).
        $this->orderAndAppendValues($values);

        $messageStore = new MessageStore();
        $isFileAvailable = $this->checkFileOrUrl($sourceFile, $messageStore);
        if (!$isFileAvailable) {
            // No stop in order to update other metadata, in particular item,
            // and usually, file are fetched later.
            $this->logger->err(reset($messageStore->getErrors()));
        }

        $result = false;
        if ($isFileAvailable) {
            $result = $this->fetchFile('original', $source['fichier'], $source['fichier'], $storageId, $extension, $sourceFile);
            if ($result) {
                $this->entity->setStorageId($storageId);
                $this->entity->setExtension(mb_strtolower($extension));
                $this->entity->setSha256($result['data']['sha256']);
                $this->entity->setMediaType($result['data']['media_type']);
                $this->entity->setHasOriginal(true);
                $this->entity->setHasThumbnails($result['data']['has_thumbnails']);
                $this->entity->setSize($result['data']['size']);
            } else {
                $this->logger->err(
                    'File "{file}" cannot be fetched.', // @translate
                    ['file' => $sourceBasename]
                );
            }
        }
        if (!$result) {
            // Message already set above.
            $this->entity->setStorageId($storageId);
            $this->entity->setExtension(mb_strtolower($extension));
            $this->entity->setHasOriginal(false);
            $this->entity->setHasThumbnails(false);
            $this->entity->setSize(0);
        }
    }

    protected function fillConcepts(): void
    {
        $sourceType = 'concepts';

        // Here the process does not use the parent methods.
        $this->prepareImport('concepts');

        // Since all concepts are merged in one table but there are multiple
        // table, do a single loop for all concepts.
        $this->configThesaurus = 'concepts';

        // Concepts are managed in 5 tables: "subject", "subject_ancestor",
        // "subject_name_lang", "subject_name_name", "subject_parents".
        // Language tables "subject__ordervalues_fr" are a dynamic merge.
        // Parent is the first ancestor.

        // Subjects are already mapped in map['concepts'], included schemes.
        $depositables = $this->reader
            ->setFilters([])
            ->setOrders([['by' => 'subjectid']])
            ->setObjectType('subject')
            ->fetchAllKeyValues('subjectid', 'depositable');
        $nameByIds = $this->reader
            ->setFilters([])
            ->setOrders([['by' => 'subjectid'], ['by' => 'pos'], ['by' => 'name_name']])
            ->setObjectType('subject_name_name')
            ->fetchAll(null, 'subjectid');
        $langByIds = $this->reader
            ->setFilters([])
            ->setOrders([['by' => 'subjectid'], ['by' => 'pos'], ['by' => 'name_lang']])
            ->setObjectType('subject_name_lang')
            ->fetchAll(null, 'subjectid');
        $parents = $this->reader
            ->setFilters([])
            ->setOrders([['by' => 'subjectid'], ['by' => 'pos'], ['by' => 'parents']])
            ->setObjectType('subject_parents')
            ->fetchAll();
        $ancestorByIds = $this->reader
            ->setFilters([])
            ->setOrders([['by' => 'subjectid'], ['by' => 'pos'], ['by' => 'ancestors']])
            ->setObjectType('subject_ancestors')
            ->fetchAll(null, 'subjectid');
        // Reset the reader.
        $this->prepareReader('concepts');

        $this->refreshMainResources();

        // All tables manage multiple data (many-to-one relations), except "subject".
        // So prepare them to be an array of array.
        $depositables = array_map(function ($v) {
            return $v === 'TRUE';
        }, $depositables);
        $parentByIds = [];
        foreach ($parents as $data) {
            $parentByIds[$data['subjectid']][] = $data;
        }

        // $classOId = ['o:id' => $this->main['classes']['skos:Concept']->getId()];
        // $templateOId = ['o:id' => $this->main['templates']['Thesaurus Concept']->getId()];

        // TODO The case where a fake thesaurus is created is not fully managed.

        $index = 0;
        $created = 0;
        $skipped = 0;
        $excluded = 0;
        foreach ($this->map['concepts'] as $sourceId => $destinationId) {
            ++$index;
            if (!$destinationId) {
                if ($sourceId === '_root_concept_') {
                    continue;
                }
                $this->hasError = true;
                $this->logger->err(
                    'No destination id for the concept "{source}".', // @translate
                    ['source' => $sourceId]
                );
                return;
            }
            $this->entity = $this->entityManager->find(\Omeka\Entity\Item::class, $destinationId);
            $this->entity->setIsPublic(true);

            $values = [];
            if (isset($this->rootConcepts[$sourceId])) {
                foreach ($parents as $parentData) {
                    if ($parentData['parents'] === $sourceId) {
                        $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$parentData['subjectid']]);
                        $values[] = [
                            'term' => 'skos:hasTopConcept',
                            'type' => 'resource:item',
                            'value_resource' => $linked,
                        ];
                    }
                }
            } else {
                $scheme = null;
                foreach ($ancestorByIds[$sourceId] ?? [] as $ancestorData) {
                    if (isset($this->rootConcepts[$ancestorData['ancestors']])) {
                        $scheme = $this->entityManager->find(\Omeka\Entity\Item::class, $this->rootConcepts[$ancestorData['ancestors']]);
                        break;
                    }
                }
                if (!$scheme) {
                    $this->hasError = true;
                    $this->logger->err(
                        'No root scheme for concept source {source}.', // @translate
                        ['source' => $sourceId]
                    );
                    return;
                }
                $values[] = [
                    'term' => 'skos:inScheme',
                    'type' => 'resource:item',
                    'value_resource' => $scheme,
                ];
                /** @see \Doctrine\ORM\PersistentCollection */
                $schemeItemSet = $scheme->getItemSets()->first();
                if ($schemeItemSet) {
                    $itemSets = $this->entity->getItemSets();
                    $itemSets->add($schemeItemSet);
                } else {
                    $this->logger->warn(
                        'The scheme item set is missing for scheme {name}.', // @translate
                        ['source' => $sourceId]
                    );
                }

                foreach ($parentByIds[$sourceId] as $parentData) {
                    if (isset($this->rootConcepts[$parentData['parents']])) {
                        $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->rootConcepts[$parentData['parents']]);
                        $values[] = [
                            'term' => 'skos:topConceptOf',
                            'type' => 'resource:item',
                            'value_resource' => $linked,
                        ];
                    } elseif ($parentData['parents'] !== 'ROOT') {
                        $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$parentData['parents']]);
                        $values[] = [
                            'term' => 'skos:broader',
                            'type' => 'resource:item',
                            'value_resource' => $linked,
                        ];
                    }
                }

                foreach ($parents as $parentData) {
                    if ($parentData['parents'] === $sourceId) {
                        $linked = $this->entityManager->find(\Omeka\Entity\Item::class, $this->map['concepts'][$parentData['subjectid']]);
                        $values[] = [
                            'term' => 'skos:narrower',
                            'type' => 'resource:item',
                            'value_resource' => $linked,
                        ];
                    }
                }
            }

            foreach ($nameByIds[$sourceId] ?? [] as $nameData) {
                $lang = null;
                foreach ($langByIds[$sourceId] ?? [] as $langData) {
                    if ($langData['pos'] === $nameData['pos']) {
                        $lang = $langData['name_lang'];
                        break;
                    }
                }
                $values[] = [
                    'term' => 'skos:prefLabel',
                    'type' => 'literal',
                    'lang' => $lang,
                    'value' => $nameData['name_name'],
                ];
            }

            $values[] = [
                'term' => 'skos:notation',
                'type' => 'literal',
                'value' => $sourceId,
            ];

            // TODO Thesaurus collection or member list are currently not managed, so use skos:member.
            if (empty($depositables[$sourceId])) {
                $values[] = [
                    'term' => 'skos:member',
                    'type' => 'literal',
                    'lang' => 'fra',
                    'value' => 'Non utilisable',
                ];
            }

            // No skos:definition.

            /*
            $item = [
                '_source_id' => $sourceId,
                '@type' => [
                    'o:Item',
                    'skos:Concept',
                ],
                'o:id' => $this->map['concepts'][$sourceId],
                'o:is_public' => true,
                'o:owner' => $this->ownerOId,
                'o:resource_class' => $classOId,
                'o:resource_template' => $templateOId,
                'o:title' => $names[$sourceId] ?? null,
                'o:created' => $this->currentDateTimeFormatted,
                'o:modified' => null,
                'o:media' => null,
                'o:item_sets' => null,
            ];
            */

            $this->orderAndAppendValues($values);

            $this->entityManager->persist($this->entity);
            ++$created;

            if ($created % self::CHUNK_ENTITIES === 0) {
                if ($this->isErrorOrStop()) {
                    break;
                }
                $this->entityManager->flush();
                $this->entityManager->clear();
                $this->refreshMainResources();
                $this->logger->info(
                    '{count}/{total} resource "{source}" imported, {skipped} skipped, {excluded} excluded.', // @translate
                    ['count' => $created, 'total' => count($this->map[$sourceType]), 'source' => $sourceType, 'skipped' => $skipped, 'excluded' => $excluded]
                );
            }
        }

        // Remaining entities.
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->refreshMainResources();

        $this->logger->notice(
            '{count}/{total} resource "{source}" imported, {skipped} skipped, {excluded} excluded.', // @translate
            ['count' => $created, 'total' => count($this->map[$sourceType]), 'source' => $sourceType, 'skipped' => $skipped, 'excluded' => $excluded]
        );
    }

    protected function fillHit(array $source, array $hit): array
    {
        /* // @link https://wiki.eprints.org/w/Access_Log_Dataset
        accessid    int(11)
        datestamp_year    smallint(6) NULL
        datestamp_month    smallint(6) NULL
        datestamp_day    smallint(6) NULL
        datestamp_hour    smallint(6) NULL
        datestamp_minute    smallint(6) NULL
        datestamp_second    smallint(6) NULL
        requester_id    varchar(255) NULL
        requester_user_agent    varchar(255) NULL
        requester_country    varchar(255) NULL
        requester_institution    varchar(255) NULL
        referring_entity_id    longtext NULL
        service_type_id    varchar(255) NULL
        referent_id    int(11) NULL
        referent_docid    int(11) NULL
         */

        $source = array_map('trim', array_map('strval', $source));

        // The source id is already checked in prepareResources().

        $created = $this->implodeDate(
            $source['datestamp_year'],
            $source['datestamp_month'],
            $source['datestamp_day'],
            $source['datestamp_hour'],
            $source['datestamp_minute'],
            $source['datestamp_second'],
            null,
            true,
            true,
            true
        ) ?: $this->currentDateTimeFormatted;

        // "referring_entity_id" may have not the same meaning than url.
        /*
        $pos = mb_strpos($source['referring_entity_id'], '?');
        if ($pos === false || $pos >= mb_strlen($source['referring_entity_id']) - 1) {
            $url = trim($source['referring_entity_id'], '?');
            // can be only "fulltext=yes" or "abstract=yes".
            $query = $source['service_type_id'];
        } else {
            $url = mb_substr($source['referring_entity_id'], 0, $pos);
            $query = mb_substr($source['referring_entity_id'], $pos + 1);
        }
        */

        // The url does not exist, only the ids. So rebuild the item id.
        // The item may have been deleted, so it may not be mapped, so keep the
        // original indexes.
        if ($source['referent_id'] && $source['referent_docid']) {
            $url = '/eprints/' . $source['referent_id'] . '/document/' . $source['referent_docid'];
        } elseif ($source['referent_id']) {
            $url = '/eprints/' . $source['referent_id'];
        } elseif ($source['referent_docid']) {
            $url = '/eprints/0/document/'. $source['referent_docid'];
        } else {
            $url = '/eprints/';
        }

        // Store the mapped id only if it exists.
        $entityId = 0;
        if ($source['referent_docid']) {
            $entityId = $this->map['media'][$source['referent_docid']] ?? 0;
            $entityName = 'media';
        } elseif ($source['referent_id']) {
            $entityId = $this->map['items'][$source['referent_docid']] ?? 0;
            $entityName = 'items';
        }
        if (!$entityId) {
            $entityName = '';
        }

        // TODO Manage "requester_country" and "requester_institution".

        return array_replace($hit, [
            'o:url' => $url,
            'o:entity_id' => $entityId,
            'o:entity_name' => $entityName,
            'o:user_id' => 0,
            'o:ip' => $source['requester_id'],
            'o:referrer' => $source['referring_entity_id'],
            'o:query' => $source['service_type_id'],
            'o:user_agent' => $source['requester_user_agent'],
            'o:accept_language' => '',
            'o:created' => ['@value' => $created],
        ]);
    }
}
