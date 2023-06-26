<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Form\Processor\EprintsProcessorConfigForm;
use BulkImport\Form\Processor\EprintsProcessorParamsForm;
use BulkImport\Stdlib\MessageStore;

/**
 * @todo Use metaMapper() instead hard coded mapping or create sql views.
 *
 * Warning: contains mapping with a special vocabulary "dante" for special columns.
 *
 * Warning: The database main values (creators, email, etc.) are case sensitive,
 * unlike Omeka.
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
            'contact_messages',
            'search_requests',
            // 'hits',
        ],
        'people_to_items' => false,
        'fake_files' => false,
        'endpoint' => null,
        'url_path' => null,
        'language' => null,
        'language_2' => null,
    ];

    protected $modules = [
        'AccessResource',
        'NumericDataTypes',
        'UserName',
        'UserProfile',
    ];

    protected $moreImportables = [
        'conductors' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'contributors' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'creators' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'directors' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'directors_other' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'editors' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'exhibitors' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        // TODO Issues is not people : add option not to create items.
        'issues' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMoreIssues',
            'is_resource' => true,
        ],
        'lyricists' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
        'producers' => [
            'name' => 'items',
            'class' => \Omeka\Entity\Item::class,
            'main_entity' => 'resources',
            'table' => 'item',
            'fill' => 'fillMorePeople',
            'is_resource' => true,
            'is_people' => true,
        ],
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
        // In eprints, main statuses are:
        // "inbox": inbox of the students, so unsubmitted contribution;
        // "buffer": contribution submitted, so transformed into an item here;
        // "archive": final status of a stored record;
        // "deletion": a document removed (fake deletion, as google).
        // Buffer and inbox may be converted into contributions
        // for module Contribute via a task.
        'items' => [
            'source' => 'eprint',
            'key_id' => 'eprintid',
            'filters' => [
                '`eprint_status` IN ("archive", "buffer", "inbox")',
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
                '`eprint`.`eprint_status` IN ("archive", "buffer", "inbox")',
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

        // Eprints multifields.

        // Warning: some fields have two tables ("eprint_conductors_id" / "eprint_conductors_name"),
        // but it is not possible to relate them: no unicity, no related third
        // table, partial data, updated data, contributor types not consistent,
        // etc. The tables "index" fix somes issues, but not all.
        // In fact, all these tables are related to eprint only and directly,
        // without an intermediate aggregating table of field. They are only
        // fields attached to eprints.
        // So they are added separately to main eprints. Ids are made private.
        // In the same way, creator ids or names can't be linked to table "user".
        // Furthermore, the composite primary key (eprint-pos) complexifies a
        // lot of requests.

        /*
        'conductors' => [
            'source' => 'eprint_conductors_id',
            'key_id' => 'conductors_id',
            'set' => 'people',
            'related' => [
                'eprint_conductors_name' => [
                    'conductors_name_family' => 'foaf:familyName',
                    'conductors_name_given' => 'foaf:givenName',
                    'conductors_name_lineage' => 'foaf:lastName',
                    'conductors_name_honourific' => 'foaf:title',
                ],
            ],
        ],
        'contributors' => [
            'source' => 'eprint_contributors_id',
            'key_id' => 'contributors_id',
            'set' => 'people',
            'related' => [
                'eprint_contributors_name' => [
                    'contributors_name_family' => 'foaf:familyName',
                    'contributors_name_given' => 'foaf:givenName',
                    'contributors_name_lineage' => 'foaf:lastName',
                    'contributors_name_honourific' => 'foaf:title',
                ],
                // TODO Use value annotation for Omeka 3.2 or use mapping of terms below.
                'eprint_contributors_type' => [
                    //The term used depends on the type.
                    'contributors_type' => [
                    ],
                ],
            ],
        ],
        // It's not possible to map creators and users: many missing data,
        // the family name may be partial, the email may have been updated,
        // there is no unicity, etc.
        'creators' => [
            'source' => 'eprint_creators_id',
            'key_id' => 'creators_id',
            'set' => 'people',
            'related' => [
                'eprint_creators_name' => [
                    'creators_name_family' => 'foaf:familyName',
                    'creators_name_given' => 'foaf:givenName',
                    'creators_name_lineage' => 'foaf:lastName',
                    'creators_name_honourific' => 'foaf:title',
                ],
            ],
        ],
        // Directors are not stored in a specific table, but in the main table.
        // There is no unique id.
        'directors' => [
            'source' => 'eprint',
            // This is a multiple key id.
            'key_id' => [
                'director_family',
                'director_given',
                'director_lineage',
                'director_honourific',
            ],
            // 'set' => 'people',
            'related' => [
                // Directors has no table: there is no id.
                // So relate to itself for simplicity to get data.
                'eprint' => [
                    'director_family' => 'foaf:familyName',
                    'director_given' => 'foaf:givenName',
                    'director_lineage' => 'foaf:lastName',
                    'director_honourific' => 'foaf:title',
                ],
            ],
        ],
        // There is no unique id for director others, so create an item for all.
        'directors_other' => [
            'source' => 'eprint_directors_other',
            'key_id' => 'directors_other_family',
            // 'set' => 'people',
            'related' => [
                // Other directors is a special table: there is no id.
                // So relate to itself for simplicity to get data.
                'eprint_directors_other' => [
                    'directors_other_family' => 'foaf:familyName',
                    'directors_other_given' => 'foaf:givenName',
                    'directors_other_lineage' => 'foaf:lastName',
                    'directors_other_honourific' => 'foaf:title',
                ],
            ],
        ],
        'editors' => [
            'source' => 'eprint_editors_id',
            'key_id' => 'editors_id',
            'set' => 'people',
            'related' => [
                'eprint_editors_name' => [
                    'editors_name_family' => 'foaf:familyName',
                    'editors_name_given' => 'foaf:givenName',
                    'editors_name_lineage' => 'foaf:lastName',
                    'editors_name_honourific' => 'foaf:title',
                ],
            ],
        ],
        'exhibitors' => [
            'source' => 'eprint_exhibitors_id',
            'key_id' => 'exhibitors_id',
            'set' => 'people',
            'related' => [
                'eprint_exhibitors_name' => [
                    'exhibitors_name_family' => 'foaf:familyName',
                    'exhibitors_name_given' => 'foaf:givenName',
                    'exhibitors_name_lineage' => 'foaf:lastName',
                    'exhibitors_name_honourific' => 'foaf:title',
                ],
            ],
        ],
        'lyricists' => [
            'source' => 'eprint_lyricists_id',
            'key_id' => 'lyricists_id',
            'set' => 'people',
            'related' => [
                'eprint_lyricists_name' => [
                    'lyricists_name_family' => 'foaf:familyName',
                    'lyricists_name_given' => 'foaf:givenName',
                    'lyricists_name_lineage' => 'foaf:lastName',
                    'lyricists_name_honourific' => 'foaf:title',
                ],
            ],
        ],
        'producers' => [
            'source' => 'eprint_producers_id',
            'key_id' => 'producers_id',
            'set' => 'people',
            'related' => [
                'eprint_producers_name' => [
                    'producers_name_family' => 'foaf:familyName',
                    'producers_name_given' => 'foaf:givenName',
                    'producers_name_lineage' => 'foaf:lastName',
                    'producers_name_honourific' => 'foaf:title',
                ],
            ],
        ],

        'issues' => [
            'source' => 'eprint_item_issues_id',
            'key_id' => 'item_issues_id',
            'set' => null,
            'related' => [
                'eprint_item_issues_comment' => [
                    'item_issues_comment' => '',
                ],
                'eprint_item_issues_description' => [
                    'item_issues_description' => '',
                ],
                'eprint_item_issues_reported_by' => [
                    // Numeric.
                    'item_issues_reported_by' => '',
                ],
                'eprint_item_issues_resolved_by' => [
                    // Numeric.
                    'item_issues_resolved_by' => '',
                ],
                'eprint_item_issues_status' => [
                    'item_issues_status' => 'bibo:status',
                ],
                'eprint_item_issues_timestamp' => [
                    // Date time
                    '_datetime' =>  'dcterms:issued',
                ],
                'eprint_item_issues_type' => [
                    // Or resource class.
                    'item_issues_type' => 'dcterms:type',
                ],
            ],
        ],
        */

        /*
        'conductors_id' => [
            'source' => 'eprint_conductors_id',
            'key_id' => 'conductors_id',
            'set' => 'eprint_id',
            'importable' => 'conductors',
        ],
        */
        'conductors' => [
            'source' => 'eprint_conductors_name',
            'key_id' => [
                'conductors_name_family' => 'foaf:familyName',
                'conductors_name_given' => 'foaf:givenName',
                'conductors_name_lineage' => 'foaf:lastName',
                'conductors_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        /*
        'contributors_id' => [
            'source' => 'eprint_contributors_id',
            'key_id' => 'contributors_id',
            'set' => 'eprint_id',
            'importable' => 'contributors',
        ],
        */
        'contributors' => [
            'source' => 'eprint_contributors_name',
            'key_id' => [
                'contributors_name_family' => 'foaf:familyName',
                'contributors_name_given' => 'foaf:givenName',
                'contributors_name_lineage' => 'foaf:lastName',
                'contributors_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        /*
        // TODO Use value annotation for Omeka 3.2 or use mapping of terms below.
        'contributors_type' => [
            'source' => 'eprint_contributors_type',
            //The term used depends on the type.
            'key_id' => 'contributors_type',
        ],
        */
        // It's not possible to map creators and users: many missing data,
        // the family name may be partial, the email may have been updated,
        // there is no unicity, etc.
        /*
        'creators_id' => [
            'source' => 'eprint_creators_id',
            'key_id' => 'creators_id',
            'set' => 'eprint_id',
            'importable' => 'creators',
        ],
        */
        'creators' => [
            'source' => 'eprint_creators_name',
            'key_id' => [
                'creators_name_family' => 'foaf:familyName',
                'creators_name_given' => 'foaf:givenName',
                'creators_name_lineage' => 'foaf:lastName',
                'creators_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        // Directors are not stored in a specific table, but in the main table.
        'directors' => [
            'source' => 'eprint',
            'no_table' => true,
            'key_id' => [
                'director_family' => 'foaf:familyName',
                'director_given' => 'foaf:givenName',
                'director_lineage' => 'foaf:lastName',
                'director_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        'directors_other' => [
            'source' => 'eprint_directors_other',
            'key_id' => [
                'directors_other_family' => 'foaf:familyName',
                'directors_other_given' => 'foaf:givenName',
                'directors_other_lineage' => 'foaf:lastName',
                'directors_other_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        /*
        'editors_id' => [
            'source' => 'eprint_editors_id',
            'key_id' => 'editors_id',
            'set' => 'eprint_id',
        ],
        */
        'editors' => [
            'source' => 'eprint_editors_name',
            'key_id' => [
                'editors_name_family' => 'foaf:familyName',
                'editors_name_given' => 'foaf:givenName',
                'editors_name_lineage' => 'foaf:lastName',
                'editors_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        /*
        'exhibitors_id' => [
            'source' => 'eprint_exhibitors_id',
            'key_id' => 'exhibitors_id',
            'set' => 'eprint_id',
            'importable' => 'exhibitors',
        ],
        */
        'exhibitors' => [
            'source' => 'eprint_exhibitors_name',
            'key_id' => [
                'exhibitors_name_family' => 'foaf:familyName',
                'exhibitors_name_given' => 'foaf:givenName',
                'exhibitors_name_lineage' => 'foaf:lastName',
                'exhibitors_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        /*
        'lyricists_id' => [
            'source' => 'eprint_lyricists_id',
            'key_id' => 'lyricists_id',
            'set' => 'eprint_id',
            'importable' => 'lyricists',
        ],
        */
        'lyricists' => [
            'source' => 'eprint_lyricists_name',
            'key_id' => [
                'lyricists_name_family' => 'foaf:familyName',
                'lyricists_name_given' => 'foaf:givenName',
                'lyricists_name_lineage' => 'foaf:lastName',
                'lyricists_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],
        /*
        'producers_id' => [
            'source' => 'eprint_producers_id',
            'key_id' => 'producers_id',
            'set' => 'eprint_id',
            'importable' => 'producers',
        ],
        */
        'producers' => [
            'source' => 'eprint_producers_name',
            'key_id' => [
                'producers_name_family' => 'foaf:familyName',
                'producers_name_given' => 'foaf:givenName',
                'producers_name_lineage' => 'foaf:lastName',
                'producers_name_honourific' => 'foaf:title',
            ],
            'set' => 'eprint_name',
            'resource_class_id' => 'foaf:Person',
            'resource_template_id' => 'Person',
        ],

        /*
        'issues' => [
            'source' => 'eprint_item_issues_id',
            'key_id' => 'item_issues_id',
        ],
        */

        // TODO Use a direct copy of the table, because it may be too much big.
        'hits' => [
            'source' => 'access',
            'key_id' => 'accessid',
        ],
        'contact_messages' => [
            'source' => 'request',
            'key_id' => 'requestid',
            'mode' => 'sql',
        ],
        'search_requests' => [
            'source' => 'saved_search',
            'key_id' => 'id',
            'mode' => 'sql',
        ],
        // history => module History Log.
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

    /**
     * Some tables to preload.
     *
     * @todo Load tables only when needed.
     */
    protected $tableData = [
        'eprint_conductors_name' => [],
        'eprint_contributors_name' => [],
        'eprint_creators_name' => [],
        'eprint_directors_other' => [],
        'eprint_editors_name' => [],
        'eprint_exhibitors_name' => [],
        'eprint_lyricists_name' => [],
        'eprint_producers_name' => [],
        //  There is no table for directors, but filled from eprint.
        // 'eprint_directors' => [],
        // 'eprint_item_issues_id' => [],
    ];

    /**
     * Some table to preload by id.
     *
     * @todo Load tables only when needed.
     * Some tables are needed flat and by id.
     */
    protected $tableDataBy = [
        'eprint_accompaniment' => [],
        'eprint_conductors_id' => [],
        'eprint_conductors_name' => [],
        'eprint_contributors_id' => [],
        'eprint_contributors_name' => [],
        'eprint_contributors_type' => [],
        'eprint_copyright_holders' => [],
        'eprint_corp_creators' => [],
        'eprint_creators_id' => [],
        'eprint_creators_name' => [],
        //  There is no table for directors, but filled from eprint.
        // 'eprint_directors' => [],
        'eprint_directors_other' => [],
        'eprint_divisions' => [],
        'eprint_editors_id' => [],
        'eprint_editors_name' => [],
        'eprint_exhibitors_id' => [],
        'eprint_exhibitors_name' => [],
        'eprint_funders' => [],
        // 'eprint_item_issues_comment' => [],
        // 'eprint_item_issues_description' => [],
        // 'eprint_item_issues_id' => [],
        // 'eprint_item_issues_reported_by' => [],
        // 'eprint_item_issues_resolved_by' => [],
        // 'eprint_item_issues_status' => [],
        // 'eprint_item_issues_timestamp' => [],
        // 'eprint_item_issues_type' => [],
        'eprint_lyricists_id' => [],
        'eprint_lyricists_name' => [],
        'eprint_producers_id' => [],
        'eprint_producers_name' => [],
        'eprint_projects' => [],
        'eprint_related_url_type' => [],
        'eprint_related_url_url' => [],
        'eprint_relation_type' => [],
        'eprint_relation_uri' => [],
        'eprint_research_unit' => [],
        'eprint_skill_areas' => [],
        'eprint_subjects' => [],
    ];

    protected function preImport(): void
    {
        $total = $this->connection->executeQuery('SELECT count(`id`) FROM `resource`;')->fetchOne();
        if ($total) {
            $this->logger->warn(
                'Even if no issues were found during tests, it is recommenced to run this importer on a database without resources.' // @translate
            );
        }

        $this->logger->warn(
            'Deleted eprints resources are not imported.' // @translate
        );

        // No config to prepare currently.
        $this->prepareConfig('config.php', 'eprints');

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

        // Prepare related tables, most of them with a few or some thousand
        // data, but not a lot, so get data one times for speed purpose.
        foreach (array_keys($this->tableData) as $table) {
            // If eprints module is not installed, skip it.
            try {
                $this->tableData[$table] = $this->reader
                    ->setFilters([])
                    ->setOrders([['by' => 'eprintid'], ['by' => 'pos']])
                    ->setObjectType($table)
                    ->fetchAll();
            } catch (\Exception $e) {
                $this->tableData[$table] = [];
            }
        }
        foreach (array_keys($this->tableDataBy) as $table) {
            // If eprints module is not installed, skip it.
            try {
                $this->tableDataBy[$table] = $this->reader
                    ->setFilters([])
                    ->setOrders([['by' => 'eprintid'], ['by' => 'pos']])
                    ->setObjectType($table)
                    ->fetchAll(null, 'eprintid');
            } catch (\Exception $e) {
                $this->tableDataBy[$table] = [];
            }
        }

        // Exception for directors.

        try {
            $this->tableData['directors'] = $this->reader
                ->setFilters([])
                ->setOrders([['by' => 'eprintid']])
                ->setObjectType('eprint')
                ->fetchAll(['eprintid', 'director_family', 'director_given', 'director_lineage', 'director_honourific']);
        } catch (\Exception $e) {
            $this->tableData['directors'] = [];
        }

        try {
            $this->tableDataBy['directors'] = $this->reader
                ->setFilters([])
                ->setOrders([['by' => 'eprintid']])
                ->setObjectType('eprint')
                ->fetchAll(['eprintid', 'director_family', 'director_given', 'director_lineage', 'director_honourific'], 'eprintid');
        } catch (\Exception $e) {
            $this->tableDataBy['directors'] = [];
        }
    }

    protected function prepareMedias(): void
    {
        $this->prepareResources($this->prepareReader('media'), 'media');
    }

    protected function prepareOthers(): void
    {
        parent::prepareOthers();

        $toImport = $this->getParam('types') ?: [];

        if (in_array('items', $toImport)
            && $this->prepareImport('items')
            && $this->getParam('people_to_items')
        ) {
            $this->logger->notice('Preparation of related resources (authors, issues, etc.).'); // @translate

            foreach ([
                // Try to follow dcterms order.
                'creators',
                'contributors',
                'directors',
                'directors_other',
                'producers',
                'editors',
                'conductors',
                'exhibitors',
                'lyricists',
                // 'issues',
            ] as $sourceType) {
                $this->logger->notice(
                    'Preparation of related resources "{name}".', // @translate
                    ['name' => $sourceType]
                );
                $table = !empty($this->mapping[$sourceType]['no_table'])
                    ? $sourceType
                    : $this->mapping[$sourceType]['source'] ?? null;
                if (!isset($this->tableData[$table])) {
                    $this->hasError = true;
                    $this->logger->err(
                        'No table for source "{source}".', // @translate
                        ['source' => $sourceType]
                    );
                    return;
                }
                if (!count($this->tableData[$table])) {
                    $this->logger->notice(
                        'Preparation of related resources "{name}": the table is empty.', // @translate
                        ['name' => $sourceType]
                    );
                    continue;
                }
                $set = $this->mapping[$sourceType]['set'] ?? null;
                switch ($set) {
                    case 'eprint_id':
                        // Skip: data are already added directly to items as multifields.
                        break;
                    case 'eprint_name':
                        $this->prepareItemsMultiKey($this->tableData[$table], $sourceType);
                        break;
                    default:
                }
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        // Other datas are imported during filling.
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
                'mapping_source' => 'concepts_' . $sourceId,
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

    protected function prepareItemsMultiKey(iterable $sources, string $sourceType): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping[$sourceType]['source'])) {
            return;
        }

        $this->map[$sourceType] = [];

        // @see fillItem() for people.
        $keyId = $this->mapping[$sourceType]['key_id'];
        if (empty($keyId)) {
            $this->hasError = true;
            $this->logger->err(
                'There is no key identifier for "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        if (!is_array($keyId)) {
            $this->hasError = true;
            $this->logger->err(
                'To manage multi keys for source "{source}", the identifier should be an array.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        // Check the size of the import.
        $this->countEntities($sources, $sourceType);
        if ($this->hasError) {
            return;
        }

        if (empty($this->totals[$sourceType])) {
            $this->logger->warn(
                'There is no "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $emptyKeyId = array_fill_keys(array_keys($keyId), null);
        $classId = empty($this->mapping[$sourceType]['resource_class_id']) ? null : $this->bulk->getResourceClassId($this->mapping[$sourceType]['resource_class_id']);
        $templateId = empty($this->mapping[$sourceType]['resource_template_id']) ? null : $this->bulk->getResourceTemplateId($this->mapping[$sourceType]['resource_template_id']);
        $thumbnailId = $this->mapping[$sourceType]['thumbnail_id'] ?? null;

        $created = 0;
        foreach ($sources as $source) {
            $orderedSource = array_intersect_key(array_replace($emptyKeyId, $source), $emptyKeyId);
            $sourceId = $this->asciiArrayToString($orderedSource);
            $this->map[$sourceType][$sourceId] = null;
            if (++$created % self::CHUNK_ENTITIES === 0) {
                if ($this->isErrorOrStop()) {
                    break;
                }
                $this->logger->info(
                    '{count} resource "{source}" prepared.', // @translate
                    ['count' => $created, 'source' => $sourceType]
                );
            }
        }

        // Because data are not yet merged, update the total here.
        $this->totals[$sourceType] = count($this->map[$sourceType]);

        if (!$this->totals[$sourceType]) {
            $this->logger->notice(
                'No resource "{source}" available on the source.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        $this->logger->notice(
            'Creation of a total of {total} resources "{source}".', // @translate
            ['total' => $this->totals[$sourceType], 'source' => $sourceType]
        );

        $resourceColumns = [
            'id' => 'id',
            'owner_id' => $this->owner ? $this->ownerId : 'NULL',
            'resource_class_id' => $classId ?: 'NULL',
            'resource_template_id' => $templateId ?: 'NULL',
            'is_public' => '0',
            'created' => '"' . $this->currentDateTimeFormatted . '"',
            'modified' => 'NULL',
            'resource_type' => $this->connection->quote($this->importables[$sourceType]['class']),
            'thumbnail_id' => $thumbnailId ?: 'NULL',
            'title' => 'id',
        ];
        $this->createEmptyEntities($sourceType, $resourceColumns, false, true, true);
        $this->createEmptyResourcesSpecific($sourceType);

        $this->logger->notice(
            '{total} resources "{source}" have been created.', // @translate
            ['total' => count($this->map[$sourceType]), 'source' => $sourceType]
        );
    }

    protected function fillUser(array $source, array $user): array
    {
        // Note: everything is string or null from the sql reader.
        /* // Table "user".
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
            'user' => empty($this->modulesActive['Guest']) ? 'researcher' : 'guest',
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
                'guest_agreed_terms' => true,
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
        /* // Table "eprint".
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
        // $isPublic = !in_array($source['ispublished'], ['unpub', 'submitted', 'inpress']);
        $isPublic = $source['eprint_status'] === 'archive';

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

        $peopleToItems = $this->getParam('people_to_items');
        $peopleToValues = !$peopleToItems;

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
        );
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
        $created = $created ?? $modified ?? $this->currentDateTimeFormatted;
        if (!$modified && $source['rev_number'] > 1) {
            $modified = $this->currentDateTimeFormatted;
        }

        $item['o:created'] = ['@value' => $created];
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

        // Created and modified are stored as private, because useless and
        // already as created/modified date of the record.
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
        );
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
        $created = $created ?? $modified;
        if ($created) {
            $values[] = [
                'term' => 'dcterms:created',
                'type' => 'numeric:timestamp',
                'value' => $created,
                'is_public' => false,
            ];
            if ($modified && $modified !== $created && $source['rev_number'] > 1) {
                $values[] = [
                    'term' => 'dcterms:modified',
                    'type' => 'numeric:timestamp',
                    'value' => $modified,
                    'is_public' => false,
                ];
            }
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

        if ($source['type']) {
            $values[] = [
                'term' => 'dcterms:type',
                'type' => $this->configs['custom_vocabs']['type'] ?? 'literal',
                'value' => $this->configs['transform']['dcterms:type'][$source['type']] ?? $source['type'],
                'is_public' => true,
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
                'term' => 'curation:tag',
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
                'type' => 'numeric:timestamp',
                'value' => $date,
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
            // TODO Create an item for the institution.
            $values[] = [
                'term' => 'dante:etablissement',
                'type' => $this->configs['custom_vocabs']['institution'] ?? 'literal',
                'value' => $source['institution'],
            ];
        }

        if ($source['department']) {
            $vrid = $this->map['concepts'][$source['department']] ?? null;
            if ($vrid) {
                $values[] = [
                    'term' => $template === 'Thèse'
                        ? 'dante:ecoleDoctorale'
                        : 'dante:ufrOuComposante',
                    'type' => ($template === 'Thèse'
                        ? $this->configs['custom_vocabs']['doctoral_school']
                        : $this->configs['custom_vocabs']['divisions']) ?? 'resource:item',
                    'value_resource' => $vrid,
                ];
            } else {
                $values[] = [
                    'term' => $template === 'Thèse' ? 'dante:ecoleDoctorale' : 'dante:ufrOuComposante',
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
            $vrid = $this->map['concepts'][$source['degrees']] ?? null;
            if ($vrid) {
                $values[] = [
                    'term' => 'bibo:degree',
                    'type' => $this->configs['custom_vocabs']['degrees'] ?? 'resource:item',
                    'value_resource' => $vrid,
                ];
            } else {
                $values[] = [
                    'term' => 'bibo:degree',
                    'value' => $source['degrees'],
                ];
            }
        }

        /* // Created separately as values or item below.
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
        */

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
                'is_public' => false,
            ];
        }

        $list = array_filter(array_map('trim', explode("\n", str_replace([',', ';', "\n"], ["\n", "\n", "\n"], (string) $source['keywords_other']))));
        foreach ($list as $keyword) {
            $values[] = [
                'term' => 'curation:tag',
                'lang' => '',
                'value' => $keyword,
            ];
        }

        /* // Duplicate of table eprint_directors_other.
        // Created separately as values or item below.
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
        */

        // Generally a title with another language, not an alternative.
        if ($source['title_other']) {
            $values[] = [
                'term' => 'dcterms:title',
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
                'type' => $this->configs['custom_vocabs']['institution_partner'] ?? 'literal',
                'value' => $source['institution_partner'],
            ];
        }

        if ($source['doctoral_school']) {
            $vrid = $this->map['concepts'][$source['doctoral_school']] ?? null;
            if ($vrid) {
                $values[] = [
                    'term' => 'dante:ecoleDoctorale',
                    'type' => $this->configs['custom_vocabs']['doctoral_school'] ?? 'resource:item',
                    'value_resource' => $vrid,
                ];
            } else {
                $values[] = [
                    'term' => 'dante:ecoleDoctorale',
                    'value' => $source['doctoral_school'],
                ];
            }
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
                'term' => 'curation:tag',
                'lang' => 'eng',
                'value' => $keyword,
            ];
        }

        /**
         * Relations.
         */

        // Relations as values or linked resources.

        // TODO Add a customvocab for role (creator, directors, etc.).
        $listValues = [
            // Try to follow dcterms order (but reordered via template anyway).
            'creators' => [
                'term' => 'dcterms:creator',
            ],
            'contributors' => [
                'term' => 'dcterms:contributor',
            ],
            'directors' => [
                'term' => 'dante:directeur',
            ],
            'directors_other' => [
                'term' => 'dante:codirecteur',
            ],
            'producers' => [
                'term' => 'bibo:producer',
            ],
            'editors' => [
                'term' => 'bibo:editor',
            ],
            //  TODO Find a better mapping for conductors, exhibitors, lyricists.
            'conductors' => [
                'term' => 'bibo:director',
            ],
            'exhibitors' => [
                'term' => 'bibo:organizer',
            ],
            'lyricists' => [
                'term' => 'bibo:performer',
            ],
            // 'issues' => [
            // ],
        ];
        if ($peopleToItems) {
            foreach ($listValues as $sourceTypeLinked => $sourceData) {
                $table = !empty($this->mapping[$sourceTypeLinked]['no_table'])
                    ? $sourceTypeLinked
                    : $this->mapping[$sourceTypeLinked]['source'] ?? null;
                $set = $this->mapping[$sourceTypeLinked]['set'] ?? null;
                if ($set !== 'eprint_name') {
                    $this->logger->warn(
                        'Attachment of "{source}" to items is currently not managed.',  // @translate
                        ['source' => $sourceTypeLinked]
                    );
                    continue;
                }
                $linkedType = $this->configs['custom_vocabs'][$sourceTypeLinked] ?? 'resource:item';
                $isPublicValue = !isset($sourceData['is_public']) || $sourceData['is_public'];
                $keyId = $this->mapping[$sourceTypeLinked]['key_id'];
                $emptyKeyId = array_fill_keys(array_keys($keyId), null);
                foreach ($this->tableDataBy[$table][$sourceId] ?? [] as $dataSource) {
                    $orderedSource = array_intersect_key(array_replace($emptyKeyId, $dataSource), $emptyKeyId);
                    $sourceLinkedId = $this->asciiArrayToString($orderedSource);
                    $vrid = $this->map[$sourceTypeLinked][$sourceLinkedId] ?? null;
                    if ($vrid) {
                        $values[] = [
                            'term' => $sourceData['term'],
                            'type' => $linkedType,
                            'value_resource' => $vrid,
                            'is_public' => $isPublicValue,
                        ];
                    } else {
                        // It should not be possible.
                        $this->hasError = true;
                        $this->logger->err(
                            'The "{source}" #"{id}" has not yet been imported as a resource.',  // @translate
                            ['source' => $sourceTypeLinked, 'id' => implode(' | ', $orderedSource)]
                        );
                    }
                }
            }
        } else {
            // By default, people to values.
            // Tables are prepared in all cases during init.
            foreach ($listValues as $sourceTypeLinked => $sourceData) {
                // @see prepareItemsMultiKey() and fillItemsMultiKey()
                // Normally already checked in AbstractFullProcessor.
                if (empty($this->mapping[$sourceTypeLinked]['source'])) {
                    return;
                }

                $keyId = $this->mapping[$sourceTypeLinked]['key_id'];
                if (empty($keyId)) {
                    $this->hasError = true;
                    $this->logger->err(
                        'There is no key identifier for "{source}".', // @translate
                        ['source' => $sourceTypeLinked]
                    );
                    return;
                }
                if (!is_array($keyId)) {
                    $this->hasError = true;
                    $this->logger->err(
                        'To manage multi keys for source "{source}", the identifier should be an array.', // @translate
                        ['source' => $sourceTypeLinked]
                    );
                    return;
                }
                $emptyKeyId = array_fill_keys(array_keys($keyId), null);

                $table = !empty($this->mapping[$sourceTypeLinked]['no_table'])
                    ? $sourceTypeLinked
                    : $this->mapping[$sourceTypeLinked]['source'] ?? null;
                $set = $this->mapping[$sourceTypeLinked]['set'] ?? null;
                if ($set !== 'eprint_name') {
                    $this->logger->warn(
                        'Attachment of "{source}" to items is currently not managed.',  // @translate
                        ['source' => $sourceTypeLinked]
                    );
                    continue;
                }
                $isPublicValue = !isset($sourceData['is_public']) || $sourceData['is_public'];
                foreach ($this->tableDataBy[$table][$sourceId] ?? [] as $dataSource) {
                    $orderedSource = array_intersect_key(array_replace($emptyKeyId, $dataSource), $emptyKeyId);
                    // TODO Create value annotation for lineage and honourific, not used in the current database.
                    $val = implode(', ', array_filter(array_map('strval', $orderedSource), 'strlen'));
                    $values[] = [
                        'term' => $sourceData['term'],
                        'type' => 'literal',
                        'value' => $val,
                        'is_public' => $isPublicValue,
                    ];
                }
            }
        }

        // Simple literal value.
        // TODO In fact, it may be subject ids, so not so simple.
        $listValues = [
            'eprint_accompaniment' => [
                'term' => 'curation:data',
                'value' => 'accompaniment',
            ],
            'eprint_copyright_holders' => [
                'term' => 'dcterms:rightsHolder',
                'value' => 'copyright_holders',
            ],
            'eprint_corp_creators' => [
                'term' => 'dcterms:creator',
                'value' => 'corp_creators',
            ],
            'eprint_funders' => [
                'term' => 'foaf:fundedBy',
                'value' => 'funders',
            ],
            'eprint_projects' => [
                'term' => 'foaf:currentProject',
                'value' => 'projects',
            ],
            'eprint_skill_areas' => [
                'term' => 'foaf:topic',
                'value' => 'skill_areas',
            ],
            // These data should not be literal values.
            'eprint_creators_id' => [
                'term' => 'dcterms:creator',
                'value' => 'creators_id',
                'is_public' => false,
            ],
            'eprint_contributors_id' => [
                'term' => 'dcterms:contributor',
                'value' => 'contributors_id',
                'is_public' => false,
            ],
            'eprint_producers_id' => [
                'term' => 'bibo:producer',
                'value' => 'producers_id',
                'is_public' => false,
            ],
            'eprint_editors_id' => [
                'term' => 'bibo:editor',
                'value' => 'editors_id',
                'is_public' => false,
            ],
            'eprint_conductors_id' => [
                'term' => 'bibo:director',
                'value' => 'conductors_id',
                'is_public' => false,
            ],
            'eprint_exhibitors_id' => [
                'term' => 'bibo:organizer',
                'value' => 'exhibitors_id',
                'is_public' => false,
            ],
            'eprint_lyricists_id' => [
                'term' => 'bibo:performer',
                'value' => 'lyricists_id',
                'is_public' => false,
            ],
        ];
        foreach ($listValues as $table => $sourceData) {
            $isPublicValue = !isset($sourceData['is_public']) || $sourceData['is_public'];
            foreach ($this->tableDataBy[$table][$sourceId] ?? [] as $data) {
                $values[] = [
                    'term' => $sourceData['term'],
                    'value' => $data[$sourceData['value']],
                    'is_public' => $isPublicValue,
                ];
            }
        }

        // Relations exceptions.

        if ($template === 'Thèse') {
            foreach ($this->tableDataBy['eprint_divisions'][$sourceId] ?? [] as $data) {
                $vrid = $this->map['concepts'][$data['divisions']] ?? null;
                if ($vrid) {
                    $values[] = [
                        'term' => 'dante:ecoleDoctorale',
                        'type' => $this->configs['custom_vocabs']['doctoral_school'] ?? 'resource:item',
                        'value_resource' => $vrid,
                    ];
                } else {
                    $values[] = [
                        'term' => 'dante:ecoleDoctorale',
                        'type' => 'literal',
                        'value_resource' => $data['divisions'],
                    ];
                }
            }
        } else {
            foreach ($this->tableDataBy['eprint_divisions'][$sourceId] ?? [] as $data) {
                $vrid = $this->map['concepts'][$data['divisions']] ?? null;
                if ($vrid) {
                    $values[] = [
                        'term' => 'dante:ufrOuComposante',
                        'type' => $this->configs['custom_vocabs']['divisions'] ?? 'resource:item',
                        'value_resource' => $vrid,
                    ];
                } else {
                    $values[] = [
                        'term' => 'dante:ufrOuComposante',
                        'type' => 'literal',
                        'value' => $data['divisions'],
                    ];
                }
            }
        }

        foreach ($this->tableDataBy['eprint_research_unit'][$sourceId] ?? [] as $data) {
            $vrid = $this->map['concepts'][$data['research_unit']] ?? null;
            if ($vrid) {
                $values[] = [
                    'term' => 'dante:uniteRecherche',
                    'type' => $this->configs['custom_vocabs']['research_unit'] ?? 'resource:item',
                    'value_resource' => $vrid,
                ];
            } else {
                $values[] = [
                    'term' => 'dante:uniteRecherche',
                    'type' => 'literal',
                    'value' => $data['research_unit'],
                ];
            }
        }

        // Subjects are divided into multiple lists (subjects, degrees,
        // divisions, doctoral_school, research unit...).

        foreach ($this->tableDataBy['eprint_subjects'][$sourceId] ?? [] as $data) {
            $vrid = $this->map['concepts'][$data['subjects']] ?? null;
            if ($vrid) {
                $values[] = [
                    'term' => 'dcterms:subject',
                    'type' => $this->configs['custom_vocabs']['eprint_subjects'] ?? 'resource:item',
                    'value_resource' => $vrid,
                ];
            } else {
                $values[] = [
                    'term' => 'dcterms:subject',
                    'type' => 'literal',
                    'value' => $data['subjects'],
                ];
            }
        }

        // TODO Find the right terms for "relation_url" and "related_url_url".
        foreach ($this->tableDataBy['eprint_relation_url'][$sourceId] ?? [] as $data) {
            $values[] = [
                'term' => 'dcterms:relation',
                'type' => 'uri',
                'uri' => $data['relation_url'],
                'value' => $this->tableDataBy['eprint_relation_type'][$sourceId][$data['pos']] ?? null,
            ];
        }

        foreach ($this->tableDataBy['eprint_related_url_url'][$sourceId] ?? [] as $data) {
            $values[] = [
                'term' => 'dcterms:references',
                'type' => 'uri',
                'uri' => $data['related_url_url'],
                'value' => $this->tableDataBy['eprint_related_url_type'][$sourceId][$data['pos']] ?? null,
            ];
        }

        // Add the previous uri, but useless when id are kept, so kept private.
        $values[] = [
            'term' => 'dcterms:identifier',
            'type' => 'uri',
            'uri' => rtrim($this->getParam('endpoint', 'https://example.org'), '/') . '/id/eprint/' . $sourceId,
            'is_public' => false,
        ];

        parent::fillItem($item);

        // Source is the current resource (this entity).
        $this->orderAndAppendValues($values);

        // To simplify next steps, store the dir path.

        $this->itemDirPaths[$sourceId] = trim($source['dir'], '/');
    }

    protected function fillMedia(array $source): void
    {
        /* Table "document".
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

        /** @var \Omeka\Entity\Item $item */
        $item = $this->entityManager->find(\Omeka\Entity\Item::class, $itemId);
        $ownerId = $item->getOwner() ? $item->getOwner()->getId() : null;

        // TODO Use the parent process to check and fetch file,(but skip media type check because the original is kept).

        // @see \Omeka\File\TempFile::getStorageId()
        $storageId = bin2hex(\Laminas\Math\Rand::getBytes(20));
        $extension = pathinfo($source['main'], PATHINFO_EXTENSION);
        $filename = $storageId . '.' . $extension;
        $endpoint = rtrim($this->getParam('url_path'), '/ ');
        $sourceBasename = '/' . $this->itemDirPaths[$sourceItemId]
            . '/' . sprintf('%02d', (int) $source['pos'])
            // TODO Check file or document.
            . '/' . $source['main'];
        $sourceFile = $endpoint . $sourceBasename;
        $isUrl = $this->bulk->isUrl($sourceFile);

        // May be "staffonly", "validuser", "public".
        // Restricted access for "validuser" is managed through module
        // AccessResource and "curation:reserved".
        $isPublic = $source['security'] === 'public';

        // TODO Use table file to get created date of the document, but same as item anyway.
        // $created =

        $class = null;
        $template = null;
        $itemTemplate = $item->getResourceTemplate();
        if ($itemTemplate) {
            $template = $this->configs['item_templates_to_media_templates'][$itemTemplate->getLabel()] ?? null;
            if ($template) {
                $templateId = $this->map['resource_templates'][$template] ?? null;
                if ($templateId) {
                    $template = ['o:id' => $templateId];
                    /** @var \Omeka\Entity\ResourceTemplate $templateEntity */
                    $templateEntity = $this->entityManager->find(\Omeka\Entity\ResourceTemplate::class, $templateId);
                    $classEntity = $templateEntity->getResourceClass();
                    if ($classEntity) {
                        $class = ['o:id' => $classEntity->getId()];
                    }
                } else {
                    $template = null;
                    // No stop.
                    $this->logger->err(
                        'No resource template for {resource_template}.', // @translate
                        ['resource_template' => $template]
                    );
                }
            }
        }

        $media = [
            // Keep the source id to simplify next steps and find mapped id.
            // The source id may be updated if duplicate.
            '_source_id' => $sourceId,
            // This is a message for the parent method to not ingest fle
            // TODO Remove the flag "do_ingest" (move fetch to parent).
            '_skip_ingest' => true,
            '@type' => [
                'o:Media',
            ],
            'o:id' => $this->map['media'][$sourceId],
            'o:is_public' => $isPublic,
            'o:owner' => $ownerId ? ['o:id' => $ownerId] : null,
            'o:resource_class' => $class,
            'o:resource_template' => $template,
            'o:title' => null,
            'o:created' => ['@value' => $item->getCreated()->format('Y-m-d\TH:i:s')],
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

        parent::fillMedia($media);

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

        // Three values for file: "public", "staffonly" or "validuser".
        // Public and staffonly are managed via public/private media.
        if ($source['security'] === 'validuser') {
            $values[] = [
                'term' => 'curation:access',
                'value' => 'reserved',
            ];
            $values[] = [
                'term' => 'curation:reserved',
                'value' => 'reserved',
            ];
        } elseif ($source['security'] === 'staffonly') {
            $values[] = [
                'term' => 'curation:access',
                'value' => 'private',
            ];
        } else {
            $values[] = [
                'term' => 'curation:access',
                'value' => 'public',
            ];
        }

        $permissionDiffusion = strtolower((string) $source['diff_permission']);
        if ($permissionDiffusion) {
            $values[] = [
                'term' => 'dante:permissionDiffusion',
                'type' => 'literal',
                'value' => $permissionDiffusion,
                'is_public' => false,
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

        if ($source['file_version']) {
            $values[] = [
                'term' => 'dante:version',
                'type' => $this->configs['custom_vocabs']['file_version'] ?? 'literal',
                'value' => $this->configs['transform']['file_version'][$source['file_version']] ?? $source['file_version'],
            ];
        }

        if ($source['dumas']) {
            $values[] = [
                'term' => 'dante:diffusionDumas',
                'type' => 'boolean',
                'value' => strtolower($source['dumas']) === 'true' ? '1' : '0',
            ];
        }

        // TODO What is the real difference between "pos" and "placement"?
        // Note: the position is set automatically in parent method according to
        // the order of media item.
        $this->entity->setPosition(1 + (int) $source['pos']);

        // Source is the current resource (this entity).
        $this->orderAndAppendValues($values);

        $messageStore = new MessageStore();
        $isFileAvailable = $this->checkFileOrUrl($sourceFile, $messageStore);
        if (!$isFileAvailable) {
            // No stop in order to update other metadata, in particular item,
            // and usually, file are fetched later.
            $message = reset($messageStore->getErrors());
            $this->logger->err(
                'File "{filename}" is not available: {error}.', // @translate
                ['filename' => $sourceBasename, 'error' => $message ?: $this->translator->translate('[unknown error]')] // @translate
            );
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

    protected function fillOthers(): void
    {
        parent::fillOthers();

        $toImport = $this->getParam('types') ?: [];

        if (in_array('items', $toImport)
            && $this->prepareImport('items')
            && $this->getParam('people_to_items')
        ) {
            $this->logger->notice('Finalization of related resources (authors, issues, etc.).'); // @translate

            foreach ([
                // Try to follow dcterms order.
                'creators',
                'contributors',
                'directors',
                'directors_other',
                'producers',
                'editors',
                'conductors',
                'exhibitors',
                'lyricists',
                // 'issues',
            ] as $sourceType) {
                $table = !empty($this->mapping[$sourceType]['no_table'])
                    ? $sourceType
                    : $this->mapping[$sourceType]['source'] ?? null;
                if (!isset($this->tableData[$table])) {
                    $this->hasError = true;
                    $this->logger->err(
                        'Finalization of related resources "{source}". No table provided.', // @translate
                        ['source' => $sourceType]
                    );
                    return;
                }
                if (!count($this->tableData[$table])) {
                    $this->logger->notice(
                        'Finalization of related resources "{name}": No resources.', // @translate
                        ['name' => $sourceType]
                    );
                    continue;
                }
                $this->logger->notice(
                    'Finalization of related resources "{name}".', // @translate
                    ['name' => $sourceType]
                );
                $set = $this->mapping[$sourceType]['set'] ?? null;
                switch ($set) {
                    case 'eprint_id':
                        // Skip: data are already added directly to items as multifields.
                        break;
                    case 'eprint_name':
                        $this->fillItemsMultiKey($this->tableData[$table], $sourceType);
                        break;
                    default:
                }
                if ($this->isErrorOrStop()) {
                    return;
                }
            }
        }

        if (!empty($this->modulesActive['ContactUs'])
            && in_array('contact_messages', $toImport)
            && $this->prepareImport('contact_messages')
        ) {
            $this->logger->notice('Import of contact messages.'); // @translate
            $this->fillContactMessages();
        }

        if (!empty($this->modulesActive['SearchHistory'])
            && in_array('search_requests', $toImport)
            && $this->prepareImport('search_requests')
        ) {
            $this->logger->notice('Import of search requests.'); // @translate
            $this->fillSearchRequests();
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
                        $values[] = [
                            'term' => 'skos:hasTopConcept',
                            'type' => 'resource:item',
                            'value_resource' => $this->map['concepts'][$parentData['subjectid']],
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
                        'No root scheme for concept source "{source}".', // @translate
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
                        $values[] = [
                            'term' => 'skos:topConceptOf',
                            'type' => 'resource:item',
                            'value_resource' => $this->rootConcepts[$parentData['parents']],
                        ];
                    } elseif ($parentData['parents'] !== 'ROOT') {
                        $values[] = [
                            'term' => 'skos:broader',
                            'type' => 'resource:item',
                            'value_resource' => $this->map['concepts'][$parentData['parents']],
                        ];
                    }
                }

                foreach ($parents as $parentData) {
                    if ($parentData['parents'] === $sourceId) {
                        $values[] = [
                            'term' => 'skos:narrower',
                            'type' => 'resource:item',
                            'value_resource' => $this->map['concepts'][$parentData['subjectid']],
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

    protected function fillItemsMultiKey(iterable $sources, string $sourceType): void
    {
        // Normally already checked in AbstractFullProcessor.
        if (empty($this->mapping[$sourceType]['source'])) {
            return;
        }

        $keyId = $this->mapping[$sourceType]['key_id'];
        if (empty($keyId)) {
            $this->hasError = true;
            $this->logger->err(
                'There is no key identifier for "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        if (!is_array($keyId)) {
            $this->hasError = true;
            $this->logger->err(
                'To manage multikeys for source "{source}", the identifier should be an array.', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        if (empty($this->totals[$sourceType])) {
            $this->logger->warn(
                'There is no "{source}".', // @translate
                ['source' => $sourceType]
            );
            return;
        }

        // TODO This is nearly a copy of fillResourcesProcess(), so factorize.
        $emptyKeyId = array_fill_keys(array_keys($keyId), null);

        if ($this->mapping[$sourceType]['resource_class_id'] === 'foaf:Person') {
            $keyFamily = array_search('foaf:familyName', $keyId);
            $keyGiven = array_search('foaf:givenName', $keyId);
        } else {
            $keyFamily = null;
            $keyGiven = null;
        }

        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $adapter = $this->adapterManager->get($this->importables[$sourceType]['name']);

        // Warning: this is derived source, so process data one time only.
        $processed = array_fill_keys($this->map[$sourceType], false);

        $index = 0;
        $created = 0;
        $skipped = 0;
        $excluded = 0;
        foreach ($sources as $source) {
            // Warning: this is derived source, so process data one time only.
            $orderedSource = array_intersect_key(array_replace($emptyKeyId, $source), $emptyKeyId);
            $sourceId = $this->asciiArrayToString($orderedSource);
            if (!empty($processed[$sourceId])) {
                continue;
            }

            ++$index;
            $processed[$sourceId] = true;

            $destinationId = $this->map[$sourceType][$sourceId] ?? null;
            if (!$destinationId) {
                ++$skipped;
                $this->logger->notice(
                    'Skipped resource "{source}" #{source_id} added in source.', // @translate
                    ['source' => $sourceType, 'source_id' => $sourceId]
                );
                continue;
            }
            $entity = $this->entityManager->find($this->importables[$sourceType]['class'], $destinationId);
            if (!$entity) {
                ++$skipped;
                $this->logger->notice(
                    'Unknown resource "{source}" #{source_id}. Probably removed during process by another user.', // @translate
                    ['source' => $sourceType, 'source_id' => $sourceId]
                );
                continue;
            }
            $this->entity = $entity;

            $values = [];
            if ($this->mapping[$sourceType]['resource_class_id'] === 'foaf:Person') {
                $familyGiven = [];
                $familyGiven[] = $source[$keyFamily] ?? null;
                $familyGiven[] = $source[$keyGiven] ?? null;
                $familyGiven = array_filter($familyGiven);
                if ($familyGiven) {
                    $values[] = [
                        'term' => 'foaf:name',
                        'type' => 'literal',
                        'value' => implode(', ', $familyGiven),
                    ];
                }
            }
            foreach ($keyId as $sourceKey => $term) {
                if (isset($source[$sourceKey]) && strlen((string) $source[$sourceKey])) {
                    $values[] = [
                        'term' => $term,
                        'type' => 'literal',
                        'value' => $source[$sourceKey],
                    ];
                }
            }

            $this->orderAndAppendValues($values);

            $errorStore = new \Omeka\Stdlib\ErrorStore;
            $adapter->validateEntity($this->entity, $errorStore);
            if ($errorStore->hasErrors()) {
                ++$skipped;
                $this->logErrors($this->entity, $errorStore);
                continue;
            }

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

        // Check total in case of an issue in the network or with Omeka < 2.1.
        // In particular, an issue occurred when a linked resource is private.
        if ($this->totals[$sourceType] !== count($this->map[$sourceType])) {
            $this->hasError = true;
            $this->logger->err(
                'The total {total} of resources "{source}" is not the same than the count {count}.', // @translate
                ['total' => $this->totals[$sourceType], 'count' => count($this->map[$sourceType]), 'source' => $sourceType]
            );
        }
    }

    protected function fillHits(): void
    {
        // The url does not exist, only the ids. So rebuild the url.
        // The item may have been deleted, so it may not be mapped, so keep the
        // original indexes in the url, but convert entity id when  possible.

        // Hits are aggregated automatically via the job AggregateHits.

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

        $sourceType = 'hits';
        if (!$this->sqlCheckReadDirectly($sourceType)) {
            return;
        }

        $dbConfig = $this->reader->getDbConfig();
        $sourceDatabase = $dbConfig['database'];
        $destinationDatabase = $this->connection->getDatabase();

        $sourceTable = $this->mapping[$sourceType]['source'];
        $sourceKeyId = $this->mapping[$sourceType]['key_id'];

        // If the table is empty, keep original ids.
        $before = $this->bulk->api()->search($sourceType)->getTotalResults();
        if (empty($before)) {
            $insertId = '`id`,';
            $selectId = "`$sourceKeyId`,";
        } else {
            $insertId = '';
            $selectId = '';
        }

        $sqls = $this->sqlTemporaryTableForIdsCreate($sourceType, 'items');
        $sqls .= $this->sqlTemporaryTableForIdsCreate($sourceType, 'media');
        $sqls .= "\n";

        // This table has no null.

        $sqls .= <<<SQL
# Mapping columns and copy source table.
# Not managed: requester_country, requester_institution.
INSERT INTO `$destinationDatabase`.`hit` (
    $insertId
    `url`,
    `entity_id`,
    `entity_name`,
    `user_id`,
    `ip`,
    `query`,
    `referrer`,
    `user_agent`,
    `accept_language`,
    `created`
)
SELECT
    $selectId
    IF(`referent_id`,
        IF(`referent_docid`,
            CONCAT("/eprints/", `referent_id`, "/document/", `referent_docid`),
            CONCAT("/eprints/", `referent_id`)
        ),
        IF(`referent_docid`,
            CONCAT("/eprints/0/document/", `referent_docid`),
            "/eprints/"
        )
    ),
    IFNULL(
        `_temporary__media`.`to`,
        IFNULL(`_temporary__items`.`to`, 0)
    ),
    IF(`_temporary__media`.`to`,
        "media",
        IF(`_temporary__items`.`to`,
            "items",
            ""
    )),
    0,
    IFNULL(`requester_id`, ""),
    IFNULL(`service_type_id`, ""),
    IFNULL(`requester_entity_id`, ""),
    IFNULL(`requester_user_agent`, ""),
    "",
    STR_TO_DATE(CONCAT(
        `datestamp_year`, "-", `datestamp_month`, "-", `datestamp_day`, " ",
        `datestamp_hour`, ":", `datestamp_minute`, ":", `datestamp_second`
    ), "%Y-%m-%d %H:%i:%s")
FROM `$sourceDatabase`.`$sourceTable`
SQL;
        $sqls .= "\n";
        $sqls .= $this->sqlTemporaryTableForIdsJoin($sourceType, 'eprintid', 'items', 'left');
        $sqls .= $this->sqlTemporaryTableForIdsJoin($sourceType, 'docid', 'media', 'left');
        $sqls .= ";\n";

        $sqls .= "\n# Drop temporary tables.\n";
        $sqls .= $this->sqlTemporaryTableForIdsDrop('items');
        $sqls .= $this->sqlTemporaryTableForIdsDrop('media');

        $this->connection->executeStatement($sqls);

        $after = $this->bulk->api()->search($sourceType)->getTotalResults();

        $this->logger->notice(
            '{total} "{source}" have been imported.', // @translate
            ['total' => $after - $before, 'source' => $sourceType]
        );
    }

    protected function fillContactMessages(): void
    {
        /* // Table "request".
        requestid    int(11)
        eprintid    int(11) NULL
        docid    varchar(255) NULL
        datestamp_year    smallint(6) NULL
        datestamp_month    smallint(6) NULL
        datestamp_day    smallint(6) NULL
        datestamp_hour    smallint(6) NULL
        datestamp_minute    smallint(6) NULL
        datestamp_second    smallint(6) NULL
        userid    int(11) NULL
        email    varchar(255) NULL
        requester_email    varchar(255) NULL
        reason    longtext NULL
        expiry_date_year    smallint(6) NULL
        expiry_date_month    smallint(6) NULL
        expiry_date_day    smallint(6) NULL
        expiry_date_hour    smallint(6) NULL
        expiry_date_minute    smallint(6) NULL
        expiry_date_second    smallint(6) NULL
        code    varchar(255) NULL
        */

        $sourceType = 'contact_messages';
        if (!$this->sqlCheckReadDirectly($sourceType)) {
            return;
        }

        $dbConfig = $this->reader->getDbConfig();
        $sourceDatabase = $dbConfig['database'];
        $destinationDatabase = $this->connection->getDatabase();

        $sourceTable = $this->mapping[$sourceType]['source'];
        $sourceKeyId = $this->mapping[$sourceType]['key_id'];

        $subject = $this->translator->translate('Request to document (migrated)'); // @translate
        $subject = $this->connection->quote($subject);

        // If the table is empty, keep original ids.
        $before = $this->bulk->api()->search($sourceType)->getTotalResults();
        if (empty($before)) {
            $insertId = '`id`,';
            $selectId = "`$sourceKeyId`,";
        } else {
            $insertId = '';
            $selectId = '';
        }

        $sqls = $this->sqlTemporaryTableForIdsCreate($sourceType, 'items');
        $sqls .= $this->sqlTemporaryTableForIdsCreate($sourceType, 'media');
        $sqls .= $this->sqlTemporaryTableForIdsCreate($sourceType, 'users');
        $sqls .= "\n";

        $sqls .= <<<SQL
# Mapping columns and copy source table.
# Not managed: expiry date; user email; code (hash).
INSERT INTO `$destinationDatabase`.`contact_message` (
    $insertId
    `owner_id`,
    `resource_id`,
    `site_id`,
    `email`,
    `name`,
    `subject`,
    `body`,
    `source`,
    `media_type`,
    `storage_id`,
    `extension`,
    `request_url`,
    `ip`,
    `user_agent`,
    `is_read`,
    `is_spam`,
    `newsletter`,
    `created`
)
SELECT
    $selectId
    `_temporary__users`.`to`,
    IFNULL(`_temporary__media`.`to`, `_temporary__items`.`to`),
    1,
    IFNULL(`requester_email`, ""),
    `requester_email`,
    $subject,
    IFNULL(`reason`, ""),
    NULL,
    NULL,
    NULL,
    NULL,
    NULL,
    "::",
    "",
    0,
    0,
    0,
    STR_TO_DATE(CONCAT(
        `datestamp_year`, "-", `datestamp_month`, "-", `datestamp_day`, " ",
        `datestamp_hour`, ":", `datestamp_minute`, ":", `datestamp_second`
    ), "%Y-%m-%d %H:%i:%s")
FROM `$sourceDatabase`.`$sourceTable`
SQL;
        $sqls .= "\n";
        $sqls .= $this->sqlTemporaryTableForIdsJoin($sourceType, 'eprintid', 'items', 'left');
        $sqls .= $this->sqlTemporaryTableForIdsJoin($sourceType, 'docid', 'media', 'left');
        $sqls .= $this->sqlTemporaryTableForIdsJoin($sourceType, 'userid', 'users', 'left');
        $sqls .= ";\n";

        $sqls .= "\n# Drop temporary tables.\n";
        $sqls .= $this->sqlTemporaryTableForIdsDrop('items');
        $sqls .= $this->sqlTemporaryTableForIdsDrop('media');
        $sqls .= $this->sqlTemporaryTableForIdsDrop('users');

        $this->connection->executeStatement($sqls);

        $after = $this->bulk->api()->search($sourceType)->getTotalResults();

        $this->logger->notice(
            '{total} "{source}" have been imported.', // @translate
            ['total' => $after - $before, 'source' => $sourceType]
        );
    }

    protected function fillSearchRequests(): void
    {
        /* // Table "saved_search".
        id    int(11)
        userid    int(11) NULL
        pos    int(11) NULL
        name    varchar(255) NULL
        spec    longtext NULL
        frequency    varchar(255) NULL
        mailempty    varchar(5) NULL
        public    varchar(5) NULL
         */

        $sourceType = 'search_requests';
        if (!$this->sqlCheckReadDirectly($sourceType)) {
            return;
        }

        $dbConfig = $this->reader->getDbConfig();
        $sourceDatabase = $dbConfig['database'];
        $destinationDatabase = $this->connection->getDatabase();

        $sourceTable = $this->mapping[$sourceType]['source'];
        $sourceKeyId = $this->mapping[$sourceType]['key_id'];

        $subject = $this->translator->translate('migrated'); // @translate
        $subject = $this->connection->quote($subject);

        // If the table is empty, keep original ids.
        $before = $this->bulk->api()->search($sourceType)->getTotalResults();
        if (empty($before)) {
            $insertId = '`id`,';
            $selectId = "`$sourceKeyId`,";
        } else {
            $insertId = '';
            $selectId = '';
        }

        $sqls = $this->sqlTemporaryTableForIdsCreate($sourceType, 'users');

        $sqls .= <<<SQL
# Mapping columns and copy source table.
# Not managed, but stored : frequency, public. Not managed: mailempty.
INSERT INTO `$destinationDatabase`.`search_request` (
    $insertId
    `user_id`,
    `site_id`,
    `comment`,
    `engine`,
    `query`,
    `created`,
    `modified`
)
SELECT
    $selectId
    `_temporary__users`.`to`,
    1,
    CONCAT(
        SUBSTR(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
            `name`, "\n\r", " "), "\n", " "), "\r", " "), "\t", " "), "  ", " ")
        ), 1, 160),
        " (", $subject, ") [",
        LOWER(`frequency`), "/", IF(`public` = "FALSE", "private", "public"), "]"
    ),
    "item",
    `spec`,
    "$this->currentDateTimeFormatted",
    NULL
FROM `$sourceDatabase`.`$sourceTable`
SQL;
        $sqls .= "\n";
        $sqls .= $this->sqlTemporaryTableForIdsJoin($sourceType, 'userid', 'users', 'left');
        $sqls .= ";\n";

        $sqls .= "\n# Drop temporary tables.\n";
        $sqls .= $this->sqlTemporaryTableForIdsDrop('users');

        $this->connection->executeStatement($sqls);

        $after = $this->bulk->api()->search($sourceType)->getTotalResults();

        $this->logger->notice(
            '{total} "{source}" have been imported.', // @translate
            ['total' => $after - $before, 'source' => $sourceType]
        );
    }
}
