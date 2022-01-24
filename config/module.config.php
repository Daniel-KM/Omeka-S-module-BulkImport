<?php declare(strict_types=1);

namespace BulkImport;

return [
    'service_manager' => [
        'factories' => [
            Processor\Manager::class => Service\Plugin\PluginManagerFactory::class,
            Reader\Manager::class => Service\Plugin\PluginManagerFactory::class,
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
        ],
    ],
    'media_ingesters' => [
        'invokables' => [
            'bulk' => Media\Ingester\Bulk::class,
        ],
        'factories' => [
            'bulk_upload' => Service\Ingester\BulkUploadFactory::class,
            'bulk_upload_dir' => Service\Ingester\BulkUploadDirFactory::class,
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
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\ArrayText::class => Form\Element\ArrayText::class,
            Form\Element\Note::class => Form\Element\Note::class,
            Form\Element\OptionalMultiCheckbox::class => Form\Element\OptionalMultiCheckbox::class,
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\Element\OptionalSelect::class => Form\Element\OptionalSelect::class,
            Form\Element\OptionalUrl::class => Form\Element\OptionalUrl::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\ConfigForm::class => \Omeka\Form\Factory\InvokableFactory::class,
            Form\ImporterDeleteForm::class => Service\Form\FormFactory::class,
            Form\ImporterForm::class => Service\Form\FormFactory::class,
            Form\ImporterStartForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemSetProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ItemSetProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\ManiocProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ManiocProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\MediaProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\MediaProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\OmekaSProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\OmekaSProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\ResourceProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\ResourceProcessorParamsForm::class => Service\Form\FormFactory::class,
            Form\Processor\SpipProcessorConfigForm::class => Service\Form\FormFactory::class,
            Form\Processor\SpipProcessorParamsForm::class => Service\Form\FormFactory::class,
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
        'factories' => [
            // Class is not used as key, since it's set dynamically by sub-route
            // and it should be available in acl (so alias is mapped later).
            'BulkImport\Controller\Admin\BulkImport' => Service\Controller\ControllerFactory::class,
            'BulkImport\Controller\Admin\Import' => Service\Controller\ControllerFactory::class,
            'BulkImport\Controller\Admin\Importer' => Service\Controller\ControllerFactory::class,
            'BulkImport\Controller\Admin\Upload' => Service\Controller\UploadControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'automapFields' => Service\ControllerPlugin\AutomapFieldsFactory::class,
            'bulk' => Service\ControllerPlugin\BulkFactory::class,
            'extractDataFromPdf' => Service\ControllerPlugin\ExtractDataFromPdfFactory::class,
            Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class => Service\ControllerPlugin\FindResourcesFromIdentifiersFactory::class,
            'processXslt' => Service\ControllerPlugin\ProcessXsltFactory::class,
            'transformSource' => Service\ControllerPlugin\TransformSourceFactory::class,
        ],
        'aliases' => [
            'findResourcesFromIdentifiers' => Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class,
            'findResourceFromIdentifier' => Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class,
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'bulk' => [
                'label' => 'Bulk Import', // @translate
                'route' => 'admin/bulk/default',
                'controller' => 'bulk-import',
                'resource' => 'BulkImport\Controller\Admin\BulkImport',
                'class' => 'o-icon-install',
                'pages' => [
                    [
                        'label' => 'Dashboard', // @translate
                        'route' => 'admin/bulk/default',
                        'controller' => 'bulk-import',
                        'resource' => 'BulkImport\Controller\Admin\BulkImport',
                        'pages' => [
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'importer',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Past Imports', // @translate
                        'route' => 'admin/bulk/default',
                        'controller' => 'import',
                        'action' => 'index',
                        'pages' => [
                            [
                                'route' => 'admin/bulk/id',
                                'controller' => 'import',
                                'visible' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ],
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
                                        'controller' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller/:id[/:action]',
                                    'constraints' => [
                                        'controller' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                            'upload' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/upload',
                                    'defaults' => [
                                        'controller' => 'Upload',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
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
            'bulkimport_local_path' => OMEKA_PATH . '/files/import',
            'bulkimport_xslt_processor' => '',
            'bulkimport_pdftk' => '',
        ],
        'settings' => [
            'bulkimport_convert_html' => [],
        ],
    ],
    'bulk_import' => [
        'readers' => [
            Reader\JsonReader::class => Reader\JsonReader::class,
            Reader\ContentDmReader::class => Reader\ContentDmReader::class,
            Reader\OmekaSReader::class => Reader\OmekaSReader::class,
            Reader\SqlReader::class => Reader\SqlReader::class,
            Reader\XmlReader::class => Reader\XmlReader::class,
            Reader\SpreadsheetReader::class => Reader\SpreadsheetReader::class,
            Reader\CsvReader::class => Reader\CsvReader::class,
            Reader\TsvReader::class => Reader\TsvReader::class,
            Reader\OpenDocumentSpreadsheetReader::class => Reader\OpenDocumentSpreadsheetReader::class,
            Reader\FakeReader::class => Reader\FakeReader::class,
        ],
        'processors' => [
            Processor\ResourceProcessor::class => Processor\ResourceProcessor::class,
            Processor\ItemProcessor::class => Processor\ItemProcessor::class,
            Processor\ItemSetProcessor::class => Processor\ItemSetProcessor::class,
            Processor\MediaProcessor::class => Processor\MediaProcessor::class,
            Processor\ManiocProcessor::class => Processor\ManiocProcessor::class,
            Processor\OmekaSProcessor::class => Processor\OmekaSProcessor::class,
            Processor\SpipProcessor::class => Processor\SpipProcessor::class,
        ],
    ],
];
