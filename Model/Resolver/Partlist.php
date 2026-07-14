<?php
declare(strict_types=1);

namespace InSession\AccessoryLinkQty\Model\Resolver;

use InSession\AccessoryLinkQty\Model\GraphQl\PartlistDataProvider;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;

/**
 * GraphQL resolver for the "partlists" field on the ProductInterface.
 * Delegates the data fetching logic to a dedicated data provider.
 */
class Partlist implements ResolverInterface
{
    /**
     * @param PartlistDataProvider $partlistDataProvider
     */
    public function __construct(
        private readonly PartlistDataProvider $partlistDataProvider
    ) {
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        if (!isset($value['model']) || !$value['model'] instanceof ProductModel) {
            return ['items' => []];
        }

        /** @var ProductModel $product */
        $product = $value['model'];
        
        /** @var StoreInterface $store */
        $store = $context->getExtensionAttributes()->getStore();
        $storeId = $store ? (int)$store->getId() : null;

        // Delegate all the hard work to the provider, passing along the store context.
        $items = $this->partlistDataProvider->getItems($product, $storeId);

        return ['items' => $items];
    }
}
