<?php declare(strict_types=1);

namespace BulkImport;

return [
    'service_manager' => [
        'factories' => [
            'Bulk\MetaMapper' => Service\Stdlib\MetaMapperFactory::class,
            'Bulk\MetaMapperConfig' => Service\Stdlib\MetaMapperConfigFactory::class,
            Processor\Manager::class => Service\PluginManagerFactory::class,
            Reader\Manager::class => Service\PluginManagerFactory::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'bulk_importeds' => Api\Adapter\ImportedAdapter::class,
            'bulk_importers' => Api\Adapter\ImporterAdapter::class,
            'bulk_imports' => Api\Adapter\ImportAdapter::class,
            'bulk_mappings' => Api\Adapter\MappingAdapter::class,
        ],
    ],
    'media_ingesters' => [
        'invokables' => [
            // This is an internal ingester.
            'bulk' => Media\Ingester\Bulk::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'controller_map' => [
            Controller\Admin\BulkImportController::class => 'bulk/admin/index',
            Controller\Admin\ImportController::class => 'bulk/admin/import',
            Controller\Admin\ImporterController::class => 'bulk/admin/importer',
            Controller\Admin\MappingController::class => 'bulk/admin/mapping',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    // TODO Merge the forms.
    'form_elements' => [
        'invokables' => [
            Form\MappingDeleteForm::class => Form\MappingDeleteForm::class,
            Form\MappingForm::class => Form\MappingForm::class,
        ],
        'factories' => [
            Form\ConfigForm::class => \Omeka\Form\Factory\InvokableFactory::class,
            Form\ImporterConfirmForm::class => Service\Form\FormFactory::class,
            Form\ImporterDeleteForm::class => Service\Form\FormFactory::class,
            Form\ImporterForm::class => Service\Form\FormFactory::class,
            Form\Mapping\AssetMappingParamsForm::class => Service\Form\FormFactory::class,
            Form\Mapping\ItemMappingParamsForm::class => Service\Form\FormFactory::class,
            Form\Mapping\ItemSetMappingParamsForm::class => Service\Form\FormFactory::class,
            Form\Mapping\MediaMappingParamsForm::class => Service\Form\FormFactory::class,
            Form\Mapping\ResourceMappingParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\AssetProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\AssetProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\EprintsProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\EprintsProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemSetProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemSetProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\MediaProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\MediaProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\OmekaSProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\OmekaSProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\ResourceProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ResourceProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\CsvReaderConfigForm::class => Service\Form\FormFactory::class,
            Form\Reader\CsvReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\JsonReaderConfigForm::class => Service\Form\FormFactory::class,
            Form\Reader\JsonReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\OmekaSReaderConfigForm::class => Service\Form\FormFactory::class,
            Form\Reader\OmekaSReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\OpenDocumentSpreadsheetReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\SpreadsheetReaderConfigForm::class => Service\Form\FormFactory::class,
            Form\Reader\SpreadsheetReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\SqlReaderConfigForm::class => Service\Form\FormFactory::class,
            Form\Reader\SqlReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\TsvReaderParamsForm::class => Service\Form\FormFactory::class,
            Form\Reader\XmlReaderConfigForm::class => Service\Form\FormFactory::class,
            Form\Reader\XmlReaderParamsForm::class => Service\Form\FormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'BulkImport\Controller\Admin\Mapping' => Controller\Admin\MappingController::class,
        ],
        'factories' => [
            // Class is not used as key, since it's set dynamically by sub-route
            // and it should be available in acl (so alias is mapped later).
            'BulkImport\Controller\Admin\BulkImport' => Service\Controller\ControllerFactory::class,
            'BulkImport\Controller\Admin\Import' => Service\Controller\ControllerFactory::class,
            'BulkImport\Controller\Admin\Importer' => Service\Controller\ControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'automapFields' => Service\ControllerPlugin\AutomapFieldsFactory::class,
            'bulk' => Service\ControllerPlugin\BulkFactory::class,
            'bulkCheckLog' => Service\ControllerPlugin\BulkCheckLogFactory::class,
            'bulkDiffResources' => Service\ControllerPlugin\BulkDiffResourcesFactory::class,
            'bulkDiffValues' => Service\ControllerPlugin\BulkDiffValuesFactory::class,
            'bulkFile' => Service\ControllerPlugin\BulkFileFactory::class,
            'bulkFileUploaded' => Service\ControllerPlugin\BulkFileUploadedFactory::class,
            'bulkIdentifiers' => Service\ControllerPlugin\BulkIdentifiersFactory::class,
            'diffResources' => Service\ControllerPlugin\DiffResourcesFactory::class,
            'metaMapperConfigList' => Service\ControllerPlugin\MetaMapperConfigListFactory::class,
            Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class => Service\ControllerPlugin\FindResourcesFromIdentifiersFactory::class,
            'processXslt' => Service\ControllerPlugin\ProcessXsltFactory::class,
            'updateResource' => Service\ControllerPlugin\UpdateResourceFactory::class,
            'updateResourceProperties' => Service\ControllerPlugin\UpdateResourcePropertiesFactory::class,
        ],
        'aliases' => [
            'findResourcesFromIdentifiers' => Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class,
            'findResourceFromIdentifier' => Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'bulk' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/bulk',
                            'defaults' => [
                                '__NAMESPACE__' => 'BulkImport\Controller\Admin',
                                '__ADMIN__' => true,
                                'controller' => 'BulkImport',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller[/:action]',
                                    'constraints' => [
                                        'controller' => 'bulk-import|importer|import|mapping',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller/:id[/:action]',
                                    'constraints' => [
                                        'controller' => 'bulk-import|importer|import|mapping',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'bulk' => [
                'label' => 'Bulk Import', // @translate
                'route' => 'admin/bulk/default',
                'controller' => 'bulk-import',
                'resource' => 'BulkImport\Controller\Admin\BulkImport',
                'class' => 'o-icon- fa-cloud-upload-alt',
                'pages' => [
                    [
                        'label' => 'Import', // @translate
                        'route' => 'admin/bulk/default',
                        'controller' => 'bulk-import',
                        'resource' => 'BulkImport\Controller\Admin\BulkImport',
                        'pages' => [
                            [
                                'route' => 'admin/bulk',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'start',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Past Imports', // @translate
                        'route' => 'admin/bulk/default',
                        'controller' => 'import',
                        'resource' => 'BulkImport\Controller\Admin\Import',
                        'pages' => [
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'import',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Configuration', // @translate
                        'route' => 'admin/bulk/default',
                        'controller' => 'importer',
                        'resource' => 'BulkImport\Controller\Admin\Importer',
                        'pages' => [
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'browse',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'add',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'edit',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'delete',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'configure-reader',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'action' => 'configure-processor',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Field mappings', // @translate
                        'route' => 'admin/bulk/default',
                        'controller' => 'mapping',
                        'resource' => 'BulkImport\Controller\Admin\Mapping',
                        'pages' => [
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'mapping',
                                'visible' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        // TODO What are the BulkImport params and logs in navigation?
        'BulkImport' => [
            [
                'label' => 'Params', // @translate
                'route' => 'admin/bulk/id',
                'controller' => 'import',
                'action' => 'show',
                'useRouteMatch' => true,
            ],
            [
                'label' => 'Logs', // @translate
                'route' => 'admin/bulk/id',
                'controller' => 'import',
                'action' => 'logs',
                'useRouteMatch' => true,
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'bulkimport' => [
        'config' => [
            'bulkimport_xslt_processor' => '',
            'bulkimport_pdftk' => '',
        ],
    ],
    'bulk_import' => [
        // Ordered by most common.
        'readers' => [
            Reader\JsonReader::class => Reader\JsonReader::class,
            Reader\SqlReader::class => Reader\SqlReader::class,
            Reader\XmlReader::class => Reader\XmlReader::class,
            Reader\SpreadsheetReader::class => Reader\SpreadsheetReader::class,
            Reader\CsvReader::class => Reader\CsvReader::class,
            Reader\TsvReader::class => Reader\TsvReader::class,
            Reader\OpenDocumentSpreadsheetReader::class => Reader\OpenDocumentSpreadsheetReader::class,
            // TODO Deprecated these reader and create pofiles for json/sql/xml/spreadsheet readers.
            Reader\ContentDmReader::class => Reader\ContentDmReader::class,
            Reader\OmekaSReader::class => Reader\OmekaSReader::class,
            Reader\FakeReader::class => Reader\FakeReader::class,
        ],
        'processors' => [
            Processor\ItemProcessor::class => Processor\ItemProcessor::class,
            Processor\ItemSetProcessor::class => Processor\ItemSetProcessor::class,
            Processor\MediaProcessor::class => Processor\MediaProcessor::class,
            Processor\ResourceProcessor::class => Processor\ResourceProcessor::class,
            Processor\AssetProcessor::class => Processor\AssetProcessor::class,
            // TODO Deprecated these processors and create meta-processor.
            Processor\EprintsProcessor::class => Processor\EprintsProcessor::class,
            Processor\OmekaSProcessor::class => Processor\OmekaSProcessor::class,
        ],
    ],
];
