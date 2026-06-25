<?php

declare(strict_types=1);

namespace InSession\AccessoryLinkQty\Block\Product\View;

use InSession\AccessoryLinkQty\Model\Product\Link as LinkModel;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Url\Helper\Data as UrlHelper;
use Zend_Db_Expr;

/**
 * Abstract base class for rendering linked product lists.
 *
 * @api
 */
abstract class AbstractLinkProducts extends AbstractProduct implements IdentityInterface
{
    /**
     * The collection of linked product items.
     *
     * @var ProductCollection|null
     */
    protected ?ProductCollection $_itemCollection = null;

    /**
     * @param Context $context
     * @param ProductVisibility $catalogProductVisibility
     * @param LinkModel $linkModel
     * @param UrlHelper $urlHelper
     * @param ProductCollectionFactory $productCollectionFactory
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly ProductVisibility $catalogProductVisibility,
        private readonly LinkModel $linkModel,
        private readonly UrlHelper $urlHelper,
        private readonly ProductCollectionFactory $productCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Abstract method to configure the link model for a specific link type.
     *
     * @param LinkModel $model
     * @return LinkModel
     */
    abstract protected function configureLinkModel(LinkModel $model): LinkModel;

    /**
     * Prepares the linked product collection.
     *
     * @return $this
     */
    protected function _prepareData(): static
    {
        if ($this->_itemCollection !== null) {
            return $this;
        }

        $current = $this->getProduct();

        if (!$current || !$current->getId()) {
            $this->_itemCollection = $this->productCollectionFactory->create();
            return $this;
        }

        try {
            $link = $this->configureLinkModel($this->linkModel);
            $linkCollection = $link->getLinkCollection()->setProduct($current);

            $linkedProductIds = array_values(array_filter(
                array_map('intval', $linkCollection->getColumnValues('linked_product_id')),
                static fn (int $id): bool => $id > 0
            ));

            if (!$linkedProductIds) {
                $this->_itemCollection = $this->productCollectionFactory->create();
                return $this;
            }

            $showDisabled = (bool) $this->getData('show_disabled_products');

            /** @var ProductCollection $collection */
            $collection = $this->productCollectionFactory->create();

            $store = $this->_storeManager->getStore();
            $storeId = (int) $store->getId();

            $collection->setStoreId($storeId);
            $collection->addStoreFilter($storeId);

            $collection->addFieldToFilter('entity_id', ['in' => $linkedProductIds]);

            if ($showDisabled) {
                $collection->addAttributeToSelect([
                    'name',
                    'sku',
                    'small_image',
                    'thumbnail',
                    'price',
                    'special_price',
                    'tax_class_id',
                    'status',
                    'visibility',
                ]);

                $collection->addMinimalPrice();
                $collection->addFinalPrice();
                $collection->addTaxPercents();
            } else {
                $this->_addProductAttributesAndPrices($collection);

                $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);

                if (!$this->getData('show_all_products')) {
                    $collection->addIsSaleableFilter();
                }

                if (!$this->getData('show_invisible_products')) {
                    $collection->setVisibility($this->catalogProductVisibility->getVisibleInCatalogIds());
                }
            }

            $collection->addUrlRewrite();

            $collection->getSelect()->order(
                new Zend_Db_Expr('FIELD(e.entity_id, ' . implode(',', $linkedProductIds) . ')')
            );

            $collection->load();

            foreach ($collection as $item) {
                $item->setDoNotUseCategoryId(true);
            }

            $this->_itemCollection = $collection;
        } catch (\Throwable $e) {
            $this->_logger->critical($e);
            $this->_itemCollection = $this->productCollectionFactory->create();
        }

        return $this;
    }

    /**
     * Get the loaded product item collection.
     *
     * @return ProductCollection
     */
    public function getItems(): ProductCollection
    {
        if ($this->_itemCollection === null) {
            $this->_prepareData();
        }

        return $this->_itemCollection;
    }

    /**
     * Get identities for caching purposes.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $ids = [];

        foreach ($this->getItems() as $item) {
            $ids = array_merge($ids, $item->getIdentities());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Get the POST parameters for adding a product to the cart.
     *
     * @param Product $product
     * @return array
     */
    public function getAddToCartPostParams(Product $product): array
    {
        $url = $this->getAddToCartUrl($product, ['_escape' => false]);

        return [
            'action' => $url,
            'data' => [
                'product' => (int) $product->getEntityId(),
                ActionInterface::PARAM_NAME_URL_ENCODED => $this->urlHelper->getEncodedUrl($url),
            ],
        ];
    }
}
