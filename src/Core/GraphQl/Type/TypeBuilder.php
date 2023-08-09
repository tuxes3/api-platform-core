<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\GraphQl\Type;

use ApiPlatform\Core\DataProvider\Pagination;
use ApiPlatform\Core\Metadata\Resource\ResourceMetadata;
use ApiPlatform\GraphQl\Serializer\ItemNormalizer;
use ApiPlatform\GraphQl\Type\TypesContainerInterface;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type as GraphQLType;
use GraphQL\Type\Definition\WrappingType;
use Psr\Container\ContainerInterface;
use Symfony\Component\PropertyInfo\Type;

/**
 * Builds the GraphQL types.
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
final class TypeBuilder implements TypeBuilderInterface
{
    /** @var TypesContainerInterface */
    private $typesContainer;
    private $defaultFieldResolver;
    private $fieldsBuilderLocator;
    private $pagination;

    public function __construct($typesContainer, callable $defaultFieldResolver, ContainerInterface $fieldsBuilderLocator, Pagination $pagination)
    {
        $this->typesContainer = $typesContainer;
        $this->defaultFieldResolver = $defaultFieldResolver;
        $this->fieldsBuilderLocator = $fieldsBuilderLocator;
        $this->pagination = $pagination;
    }

    /**
     * {@inheritdoc}
     */
    public function getResourceObjectType(?string $resourceClass, ResourceMetadata $resourceMetadata, bool $input, ?string $queryName, ?string $mutationName, ?string $subscriptionName, bool $wrapped = false, int $depth = 0): GraphQLType
    {
        $shortName = $resourceMetadata->getShortName();

        if (null !== $mutationName) {
            $shortName = $mutationName.ucfirst($shortName);
        }
        if (null !== $subscriptionName) {
            $shortName = $subscriptionName.ucfirst($shortName).'Subscription';
        }

        $isInterface = (new \ReflectionClass($resourceClass))->isAbstract() &&
            !(null !== $mutationName || null !== $subscriptionName);

        if ($input) {
            if ($depth > 0) {
                $shortName .= 'Nested';
            }
            $shortName .= 'Input';
        } elseif (null !== $mutationName || null !== $subscriptionName) {
            if ($depth > 0) {
                $shortName .= 'Nested';
            }
            $shortName .= 'Payload';
        }
        if (('item_query' === $queryName || 'collection_query' === $queryName)
            && $resourceMetadata->getGraphqlAttribute('item_query', 'normalization_context', [], true) !== $resourceMetadata->getGraphqlAttribute('collection_query', 'normalization_context', [], true)) {
            if ('item_query' === $queryName) {
                $shortName .= 'Item';
            }
            if ('collection_query' === $queryName) {
                $shortName .= 'Collection';
            }
        }
        if ($wrapped && (null !== $mutationName || null !== $subscriptionName)) {
            $shortName .= 'Data';
        }

        if ($this->typesContainer->has($shortName)) {
            $resourceObjectType = $this->typesContainer->get($shortName);
            if (!($resourceObjectType instanceof ObjectType || $resourceObjectType instanceof NonNull || $resourceObjectType instanceof InterfaceType)) {
                throw new \LogicException(sprintf('Expected GraphQL type "%s" to be %s.', $shortName, implode('|', [ObjectType::class, NonNull::class, InterfaceType::class])));
            }

            return $resourceObjectType;
        }

        $ioMetadata = $resourceMetadata->getGraphqlAttribute($subscriptionName ?? $mutationName ?? $queryName, $input ? 'input' : 'output', null, true);
        if (null !== $ioMetadata && \array_key_exists('class', $ioMetadata) && null !== $ioMetadata['class']) {
            $resourceClass = $ioMetadata['class'];
        }

        $wrapData = !$wrapped && (null !== $mutationName || null !== $subscriptionName) && !$input && $depth < 1;

        $configuration = [
            'name' => $shortName,
            'description' => $resourceMetadata->getDescription(),
            'fields' => function () use ($resourceClass, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, $wrapData, $depth, $ioMetadata) {
                if ($wrapData) {
                    $queryNormalizationContext = $resourceMetadata->getGraphqlAttribute($queryName ?? '', 'normalization_context', [], true);
                    $mutationNormalizationContext = $resourceMetadata->getGraphqlAttribute($mutationName ?? $subscriptionName ?? '', 'normalization_context', [], true);
                    // Use a new type for the wrapped object only if there is a specific normalization context for the mutation or the subscription.
                    // If not, use the query type in order to ensure the client cache could be used.
                    $useWrappedType = $queryNormalizationContext !== $mutationNormalizationContext;

                    $fields = [
                        lcfirst($resourceMetadata->getShortName()) => $useWrappedType ?
                            $this->getResourceObjectType($resourceClass, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, true, $depth) :
                            $this->getResourceObjectType($resourceClass, $resourceMetadata, $input, $queryName ?? 'item_query', null, null, true, $depth),
                    ];

                    if (null !== $subscriptionName) {
                        $fields['clientSubscriptionId'] = GraphQLType::string();
                        if ($resourceMetadata->getAttribute('mercure', false)) {
                            $fields['mercureUrl'] = GraphQLType::string();
                        }

                        return $fields;
                    }

                    return $fields + ['clientMutationId' => GraphQLType::string()];
                }

                /** @var FieldsBuilderInterface $fieldsBuilder */
                $fieldsBuilder = $this->fieldsBuilderLocator->get('api_platform.graphql.fields_builder');
                $fields = $fieldsBuilder->getResourceObjectTypeFields($resourceClass, $resourceMetadata, $input, $queryName, $mutationName, $subscriptionName, $depth, $ioMetadata);

                if ($input && null !== $mutationName && null !== $mutationArgs = $resourceMetadata->getGraphql()[$mutationName]['args'] ?? null) {
                    return $fieldsBuilder->resolveResourceArgs($mutationArgs, $mutationName, $resourceMetadata->getShortName()) + ['clientMutationId' => $fields['clientMutationId']];
                }

                return $fields;
            },
            'interfaces' => $wrapData ? [] : [$this->getNodeInterface()],
        ];

        if ($isInterface) {
            $configuration['resolveType'] = function ($value, $context, $info) {
                if (!isset($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY])) {
                    throw new \UnexpectedValueException('Resource class was not passed. Interface type can not be used.');
                }

                $shortName = (new \ReflectionClass($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY]))->getShortName();

                if (!$this->typesContainer->has($shortName)) {
                    throw new \UnexpectedValueException("Type with name $shortName can not be found");
                }

                $type = $this->typesContainer->get($shortName);
                if (!isset($type->config['interfaces'])) {
                    throw new \UnexpectedValueException("Type \"$shortName\" doesn't implement any interface.");
                }

                foreach ($type->config['interfaces'] as $interface) {
                    $returnType = $info->returnType;

                    while($returnType instanceof WrappingType) {
                        $returnType = $returnType->getWrappedType();
                    }

                    if ($interface === $returnType) {
                        return $type;
                    }
                }

                throw new \UnexpectedValueException("Type \"$type\" must implement interface \"$info->returnType\"");
            };
        } else {
            $configuration['interfaces'] = $wrapData ? [] : array_merge([$this->getNodeInterface()], $this->getInterfaceTypes($resourceClass));
            $configuration['resolveField'] = $this->defaultFieldResolver;
        }

        if($input) {
            $resourceObjectType = GraphQLType::nonNull(new InputObjectType($configuration));
        } elseif($isInterface) {
            $resourceObjectType = new InterfaceType($configuration);
        } else {
            $resourceObjectType = new ObjectType($configuration);
        }
        $this->typesContainer->set($shortName, $resourceObjectType);

        return $resourceObjectType;
    }

    private function getInterfaceTypes(string $mainClass): array
    {
        $interfaceTypes = [];
        foreach (class_parents($mainClass) as $resourceClass) {
            try {
                $reflection = new \ReflectionClass($resourceClass);
            } catch (\ReflectionException $e) {
                throw new \UnexpectedValueException("Class $resourceClass can't be found.");
            }

            $typeName = $reflection->getShortName();

            if($this->typesContainer->has($typeName)) $interfaceTypes[] = $this->typesContainer->get($typeName);
        }

        return $interfaceTypes;
    }

    /**
     * {@inheritdoc}
     */
    public function getNodeInterface(): InterfaceType
    {
        if ($this->typesContainer->has('Node')) {
            $nodeInterface = $this->typesContainer->get('Node');
            if (!$nodeInterface instanceof InterfaceType) {
                throw new \LogicException(sprintf('Expected GraphQL type "Node" to be %s.', InterfaceType::class));
            }

            return $nodeInterface;
        }

        $nodeInterface = new InterfaceType([
            'name' => 'Node',
            'description' => 'A node, according to the Relay specification.',
            'fields' => [
                'id' => [
                    'type' => GraphQLType::nonNull(GraphQLType::id()),
                    'description' => 'The id of this node.',
                ],
            ],
            'resolveType' => function ($value) {
                if (!isset($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY])) {
                    return null;
                }

                $shortName = (new \ReflectionClass($value[ItemNormalizer::ITEM_RESOURCE_CLASS_KEY]))->getShortName();

                return $this->typesContainer->has($shortName) ? $this->typesContainer->get($shortName) : null;
            },
        ]);

        $this->typesContainer->set('Node', $nodeInterface);

        return $nodeInterface;
    }

    /**
     * {@inheritdoc}
     */
    public function getResourcePaginatedCollectionType(GraphQLType $resourceType, string $resourceClass, string $operationName): GraphQLType
    {
        $shortName = $resourceType->name;
        $paginationType = $this->pagination->getGraphQlPaginationType($resourceClass, $operationName);

        $connectionTypeKey = sprintf('%s%sConnection', $shortName, ucfirst($paginationType));
        if ($this->typesContainer->has($connectionTypeKey)) {
            return $this->typesContainer->get($connectionTypeKey);
        }

        $paginationType = $this->pagination->getGraphQlPaginationType($resourceClass, $operationName);

        $fields = 'cursor' === $paginationType ?
            $this->getCursorBasedPaginationFields($resourceType) :
            $this->getPageBasedPaginationFields($resourceType);

        $configuration = [
            'name' => $connectionTypeKey,
            'description' => sprintf("%s connection for $shortName.", ucfirst($paginationType)),
            'fields' => $fields,
        ];

        $resourcePaginatedCollectionType = new ObjectType($configuration);
        $this->typesContainer->set($connectionTypeKey, $resourcePaginatedCollectionType);

        return $resourcePaginatedCollectionType;
    }

    /**
     * {@inheritdoc}
     */
    public function isCollection(Type $type): bool
    {
        return $type->isCollection() && ($collectionValueType = method_exists(Type::class, 'getCollectionValueTypes') ? ($type->getCollectionValueTypes()[0] ?? null) : $type->getCollectionValueType()) && null !== $collectionValueType->getClassName();
    }

    private function getCursorBasedPaginationFields(GraphQLType $resourceType): array
    {
        $shortName = $resourceType->name;

        $edgeObjectTypeConfiguration = [
            'name' => "{$shortName}Edge",
            'description' => "Edge of $shortName.",
            'fields' => [
                'node' => $resourceType,
                'cursor' => GraphQLType::nonNull(GraphQLType::string()),
            ],
        ];
        $edgeObjectType = new ObjectType($edgeObjectTypeConfiguration);
        $this->typesContainer->set("{$shortName}Edge", $edgeObjectType);

        $pageInfoObjectTypeConfiguration = [
            'name' => "{$shortName}PageInfo",
            'description' => 'Information about the current page.',
            'fields' => [
                'endCursor' => GraphQLType::string(),
                'startCursor' => GraphQLType::string(),
                'hasNextPage' => GraphQLType::nonNull(GraphQLType::boolean()),
                'hasPreviousPage' => GraphQLType::nonNull(GraphQLType::boolean()),
            ],
        ];
        $pageInfoObjectType = new ObjectType($pageInfoObjectTypeConfiguration);
        $this->typesContainer->set("{$shortName}PageInfo", $pageInfoObjectType);

        return [
            'edges' => GraphQLType::listOf($edgeObjectType),
            'pageInfo' => GraphQLType::nonNull($pageInfoObjectType),
            'totalCount' => GraphQLType::nonNull(GraphQLType::int()),
        ];
    }

    private function getPageBasedPaginationFields(GraphQLType $resourceType): array
    {
        $shortName = $resourceType->name;

        $paginationInfoObjectTypeConfiguration = [
            'name' => "{$shortName}PaginationInfo",
            'description' => 'Information about the pagination.',
            'fields' => [
                'itemsPerPage' => GraphQLType::nonNull(GraphQLType::int()),
                'lastPage' => GraphQLType::nonNull(GraphQLType::int()),
                'totalCount' => GraphQLType::nonNull(GraphQLType::int()),
            ],
        ];
        $paginationInfoObjectType = new ObjectType($paginationInfoObjectTypeConfiguration);
        $this->typesContainer->set("{$shortName}PaginationInfo", $paginationInfoObjectType);

        return [
            'collection' => GraphQLType::listOf($resourceType),
            'paginationInfo' => GraphQLType::nonNull($paginationInfoObjectType),
        ];
    }
}
