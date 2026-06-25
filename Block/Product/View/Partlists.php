<?php

declare(strict_types=1);

namespace InSession\AccessoryLinkQty\Block\Product\View;

use InSession\AccessoryLinkQty\Model\Partlists as PartlistsModel;
use InSession\AccessoryLinkQty\Model\Product\Link as LinkModel;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product\Visibility as ProductVisibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Url\Helper\Data as UrlHelper;

/**
 * Block for displaying 'Partlists' linked products on the product detail page.
 */
class Partlists extends AbstractLinkProducts
{
    private const PARTLIST_CACHE_TAG_PREFIX = 'insession_alq_partlists';

    /**
     * @param Context $context
     * @param ProductVisibility $catalogProductVisibility
     * @param LinkModel $linkModel
     * @param UrlHelper $urlHelper
     * @param ProductCollectionFactory $productCollectionFactory
     * @param PartlistsModel $partlistsModel
     * @param array $data
     */
    public function __construct(
        Context $context,
        ProductVisibility $catalogProductVisibility,
        LinkModel $linkModel,
        UrlHelper $urlHelper,
        ProductCollectionFactory $productCollectionFactory,
        private readonly PartlistsModel $partlistsModel,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $catalogProductVisibility,
            $linkModel,
            $urlHelper,
            $productCollectionFactory,
            $data
        );
    }

    /**
     * Configures the link model to use the 'partlists' link type.
     *
     * @param LinkModel $model
     * @return LinkModel
     */
    protected function configureLinkModel(LinkModel $model): LinkModel
    {
        return $model->usePartlistsLinks();
    }

    /**
     * Return identities for the current PDP and actually resolved partlist products only.
     *
     * Important:
     * The parent implementation uses getItems(), which can resolve a broader linked-product
     * collection than the partlist output actually uses. On products without visible partlists,
     * this can add hundreds of unrelated cat_p_* tags to the PDP.
     *
     * This override uses getItemsWithQty(), the same data path used by the template output.
     *
     * @return string[]
     */
    public function getIdentities(): array
    {
        $current = $this->getProduct();

        if (!$current || !$current->getId()) {
            return [];
        }

        $currentProductId = (int) $current->getId();

        $identities = array_merge(
            $current->getIdentities(),
            [self::PARTLIST_CACHE_TAG_PREFIX . '_' . $currentProductId]
        );

        foreach ($this->getItemsWithQty() as $item) {
            $product = $item['product'] ?? null;

            if ($product instanceof ProductInterface) {
                $identities = array_merge($identities, $product->getIdentities());
            }
        }

        return array_values(array_unique($identities));
    }

    /**
     * Get the title for the block.
     * The title can be set via layout XML argument 'title'.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->getData('title') ?: (string) __('Partlist');
    }

    /**
     * Get items enriched with qty and position, sorted by position.
     *
     * @return array<int,array{product:ProductInterface, qty:float, position:int}>
     */
    public function getItemsWithQty(): array
    {
        $current = $this->getProduct();

        if (!$current || !$current->getId()) {
            return [];
        }

        $linkCollection = $this->partlistsModel->getPartlistsLinkCollection($current);

        if (!$linkCollection->getSize()) {
            return [];
        }

        $qtyById = [];
        $posById = [];
        $ids = [];

        foreach ($linkCollection as $row) {
            $linkedProductId = (int) $row->getLinkedProductId();

            if ($linkedProductId <= 0) {
                continue;
            }

            $ids[] = $linkedProductId;
            $qtyById[$linkedProductId] = max(0.0, (float) $row->getQty());
            $posById[$linkedProductId] = (int) $row->getPosition();
        }

        if (!$ids) {
            return [];
        }

        $items = [];

        foreach ($this->getItems() as $product) {
            /** @var ProductInterface $product */
            $productId = (int) $product->getId();

            if (!in_array($productId, $ids, true)) {
                continue;
            }

            $qty = $qtyById[$productId] ?? 1.0;

            if ($qty <= 0) {
                $qty = 1.0;
            }

            $items[] = [
                'product' => $product,
                'qty' => $qty,
                'position' => $posById[$productId] ?? 0,
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ($a['position'] <=> $b['position'])
        );

        return $items;
    }
}
