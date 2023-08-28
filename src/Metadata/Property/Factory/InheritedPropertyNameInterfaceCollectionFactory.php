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

namespace ApiPlatform\Metadata\Property\Factory;

use ApiPlatform\Metadata\Exception\ResourceClassNotFoundException;
use ApiPlatform\Metadata\Property\PropertyNameCollection;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;

/**
 * Creates a property name collection from eventual child inherited properties.
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class InheritedPropertyNameInterfaceCollectionFactory implements PropertyNameCollectionFactoryInterface
{
    private $resourceNameCollectionFactory;
    private $decorated;
    private $propertyInfo;

    public function __construct(ResourceNameCollectionFactoryInterface $resourceNameCollectionFactory,
                                PropertyNameCollectionFactoryInterface $propertyInfo,
                                PropertyNameCollectionFactoryInterface $decorated = null)
    {
        $this->resourceNameCollectionFactory = $resourceNameCollectionFactory;
        $this->decorated = $decorated;
        $this->propertyInfo = $propertyInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function create(string $resourceClass, array $options = []): PropertyNameCollection
    {
        $propertyNames = [];

        try {
            $resourceMetadata = $this->decorated->create($resourceClass);
        } catch (ResourceClassNotFoundException $e) {
            $resourceMetadata = null;
        }

        $isInterface = (new \ReflectionClass($resourceClass))->isAbstract();

        // Fallback to decorated factory
        if (!isset($resourceMetadata) || !$isInterface) {
            return $this->decorated
                ? $this->decorated->create($resourceClass, $options)
                : new PropertyNameCollection(array_values($propertyNames));
        }

        // Inherited from parent
        if ($this->decorated) {
            if ($this->decorated instanceof PropertyInfoPropertyNameCollectionFactory) {
                // InheritedPropertyNameCollectionFactory doesnt work for interfaces
                foreach ($this->propertyInfo->create($resourceClass, $options) as $propertyName) {
                    $propertyNames[$propertyName] = (string) $propertyName;
                }
            } else {
                foreach ($this->decorated->create($resourceClass, $options) as $propertyName) {
                    $propertyNames[$propertyName] = (string) $propertyName;
                }
            }
        }

        foreach ($this->resourceNameCollectionFactory->create() as $knownResourceClass) {
            if (is_subclass_of($resourceClass, $knownResourceClass)) {
                foreach ($this->create($knownResourceClass) as $propertyName) {
                    $propertyNames[$propertyName] = $propertyName;
                }
            }
        }

        return new PropertyNameCollection(array_values($propertyNames));
    }
}
