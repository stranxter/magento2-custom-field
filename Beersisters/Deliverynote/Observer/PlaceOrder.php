<?php
declare(strict_types=1);

namespace Beersisters\Deliverynote\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Carries the delivery note from the quote onto the order during submit.
 *
 * Observes sales_model_service_quote_submit_before. The order is NOT saved here: the
 * submit process persists it immediately afterwards, and saving mid-submit can
 * double-write or deadlock.
 */
class PlaceOrder implements ObserverInterface
{
    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        CartRepositoryInterface $cartRepository,
        LoggerInterface $logger
    ) {
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order|null $order */
        $order = $observer->getEvent()->getOrder();
        if ($order === null) {
            return;
        }

        /**
         * The event carries the quote directly, so in the normal path no reload is needed.
         *
         * @var \Magento\Quote\Model\Quote|null $quote
         */
        $quote = $observer->getEvent()->getQuote();

        if ($quote === null) {
            $quoteId = (int)$order->getQuoteId();
            if ($quoteId === 0) {
                return;
            }

            try {
                /** @var \Magento\Quote\Model\Quote $quote */
                $quote = $this->cartRepository->get($quoteId);
            } catch (NoSuchEntityException $e) {
                $this->logger->warning(
                    sprintf(
                        'Beersisters_Deliverynote: quote %d not found while placing an order; '
                        . 'the delivery note was not carried over.',
                        $quoteId
                    )
                );

                return;
            }
        }

        $order->setDeliveryNote($quote->getDeliveryNote());
    }
}
