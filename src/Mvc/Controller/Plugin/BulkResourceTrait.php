<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

trait BulkResourceTrait
{
    /**
     * Normalize a list of property values to allow a strict comparaison.
     *
     * @todo Add an aggregated value to simplify comparison.
     */
    public function normalizePropertyValues(string $term, ?array $values): array
    {
        if (!$values) {
            return [];
        }

        $propertyId = $this->bulk->getPropertyId($term);

        $order = [
            'type' => null,
            'property_id' => $propertyId,
            // 'property_label' => null,
            'is_public' => true,
            '@annotations' => [],
            '@value' => null,
            '@id' => null,
            'value_resource_id' => null,
            '@language' => null,
        ];

        foreach ($values as $key => $value) {
            $values[$key] = array_replace($order, array_intersect_key($value, $order));
            $values[$key] = [
                'type' => empty($values[$key]['type']) ? 'literal' : (string) $values[$key]['type'],
                'property_id' => $propertyId,
                'is_public' => is_null($values[$key]['is_public']) ? true : (bool) $values[$key]['is_public'],
                '@annotations' => empty($values[$key]['@annotations']) || !is_array($values[$key]['@annotations']) ? [] : $values[$key]['@annotations'],
                '@value' => is_scalar($values[$key]['@value']) ? (string) $values[$key]['@value'] : $values[$key]['@value'],
                '@id' => empty($values[$key]['@id']) ? null : (string) $values[$key]['@id'],
                'value_resource_id' => empty($values[$key]['value_resource_id']) ? null : (int) $values[$key]['value_resource_id'],
                '@language' => empty($values[$key]['@language']) ? null : (string) $values[$key]['@language'],
            ];
        }

        return $values;
    }

    /**
     * Fully recursive serialization of a resource without issue.
     *
     * jsonSerialize() does not serialize all sub-data and an error can occur
     * with them with some events.
     * `json_decode(json_encode($resource), true)`cannot be used, because in
     * some cases, for linked resources, there may be rights issues, or the
     * resource may be not reloaded but a partial doctrine entity converted into
     * a partial representation. So there may be missing linked resources, so a
     * fatal error can occur when converting a value resource to its reference.
     * So the serialization is done manually.
     *
     * @todo Find where the issues occurs (during a spreadsheet update on the second row).
     * @todo Check if the issue occurs with value annotations.
     * @todo Check if this issue is still existing in v4.
     */
    public function resourceJson(?AbstractResourceEntityRepresentation $resource): array
    {
        if (!$resource) {
            return [];
        }

        $propertyIds = $this->bulk->getPropertyIds();

        // This serialization does not serialize sub-objects as array.
        $resourceArray = $resource->jsonSerialize();

        // There is only issue for properties.
        $repr = array_diff_key($resourceArray, $propertyIds);
        $repr = json_decode(json_encode($repr), true);

        $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4', '<');

        $propertiesWithoutResource = array_intersect_key($resourceArray, $propertyIds);
        foreach ($propertiesWithoutResource as $term => $values) {
            /** @var \Omeka\Api\Representation\ValueRepresentation|array $value */
            foreach ($values as $value) {
                // In some cases (module event), the value is already an array.
                if (is_object($value)) {
                    $valueType = $value->type();
                    // The issue occurs for linked resources.
                    try {
                        $vr = $value->valueResource();
                        if ($vr) {
                            $repr[$term][] = [
                                'type' => $valueType,
                                'property_id' => $propertyIds[$term],
                                // 'property_label' => null,
                                'is_public' => $value->isPublic(),
                                '@annotations' => $isOldOmeka ? [] : $value->valueAnnotation(),
                                // '@id' => $vr->apiUrl(),
                                'value_resource_id' => (int) $vr->id(),
                                '@language' => $value->lang() ?: null,
                                // 'url' => null,
                                // 'display_title' => $vr->displayTitle(),
                            ];
                        } else {
                            $repr[$term][] = json_decode(json_encode($value), true);
                        }
                    } catch (\Exception $e) {
                        if ($this->bulk->getMainDataType($valueType) === 'resource') {
                            $this->logger->warn(
                                'The {resource} #{id} has a linked resource or an annotation for term {term} that is not available and cannot be serialized.', // @translate
                                ['resource' => $resource->resourceName(), 'id' => $resource->id(), 'term' => $term]
                            );
                        } else {
                            try {
                                $repr[$term][] = $value->jsonSerialize();
                            } catch (\Exception $e) {
                                $this->logger->warn(
                                    'The {resource} #{id} has a linked resource or an annotation for term {term} that is not available and cannot be serialized.', // @translate
                                    ['resource' => $resource->resourceName(), 'id' => $resource->id(), 'term' => $term]
                                );
                            }
                        }
                    }
                } else {
                    $repr[$term][] = $value;
                }
            }
        }

        return $repr;
    }
}
