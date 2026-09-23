<?php
declare(strict_types=1);

namespace Beersisters\Deliverynote\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Renders the customer-supplied delivery note on the admin order view page.
 *
 * The note is attacker-controlled (it arrives from an unauthenticated storefront POST),
 * so every consumer of getDeliveryNote() MUST escape it on output.
 */
class DisplayCustomValue extends Template
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var string|null
     */
    private $deliveryNote;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        array $data = []
    ) {
        $this->orderRepository = $orderRepository;
        parent::__construct($context, $data);
    }

    /**
     * Delivery note for the order currently being viewed.
     *
     * Returns an empty string when there is no order or no note, so callers never
     * have to null-check. The value is raw, unescaped user input.
     */
    public function getDeliveryNote(): string
    {
        if ($this->deliveryNote !== null) {
            return $this->deliveryNote;
        }

        $this->deliveryNote = '';

        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId === 0) {
            return $this->deliveryNote;
        }

        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            return $this->deliveryNote;
        }

        $this->deliveryNote = trim((string)$order->getData('delivery_note'));

        return $this->deliveryNote;
    }
}
