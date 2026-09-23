<?php
declare(strict_types=1);

namespace Beersisters\Deliverynote\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores the customer's delivery note on the active quote.
 *
 * This endpoint is reachable by any storefront visitor (guest checkout), so it is
 * deliberately POST-only, form-key validated and length-bounded. The stored value is
 * raw user input and MUST be escaped wherever it is rendered.
 *
 * Class name is intentionally left lower-cased to match the existing file name and the
 * URL already in use; renaming it is a case-only rename that is unsafe to perform on a
 * case-insensitive filesystem and buys nothing at runtime.
 */
class saveInQuote implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * Upper bound on a stored note. Without one, an unauthenticated caller can write
     * unbounded data into the quote table.
     */
    public const MAX_NOTE_LENGTH = 1000;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var FormKeyValidator
     */
    private $formKeyValidator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        RequestInterface $request,
        CheckoutSession $checkoutSession,
        CartRepositoryInterface $cartRepository,
        JsonFactory $resultJsonFactory,
        FormKeyValidator $formKeyValidator,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->checkoutSession = $checkoutSession;
        $this->cartRepository = $cartRepository;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->logger = $logger;
    }

    /**
     * Real form-key validation.
     *
     * The method MUST be named validateForCsrf - that is what
     * Magento\Framework\App\CsrfAwareActionInterface declares. There is no
     * validateForRequest anywhere in the framework; naming it that way makes the class
     * abstract-by-omission and PHP fatals at compile time.
     *
     * Note for future maintainers: do NOT "simplify" this to `return true;`. That is the
     * common copy-paste that silently disables CSRF protection on the endpoint.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyValidator->validate($request);
    }

    /**
     * Answer a failed CSRF check with JSON rather than Magento's default HTML error page,
     * since the only caller is an AJAX request expecting JSON.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $message = __('Invalid form key. Please refresh the page and try again.');

        $result = $this->resultJsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData(['success' => false, 'message' => (string)$message]);

        return new InvalidRequestException($result, [$message]);
    }

    /**
     * @return Json
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();

        $noteVal = trim((string)$this->request->getParam('noteVal', ''));

        if (mb_strlen($noteVal) > self::MAX_NOTE_LENGTH) {
            $result->setHttpResponseCode(400);

            return $result->setData([
                'success' => false,
                'message' => (string)__('The note may not be longer than %1 characters.', self::MAX_NOTE_LENGTH),
            ]);
        }

        $quoteId = $this->checkoutSession->getQuoteId();
        if (!$quoteId) {
            $result->setHttpResponseCode(400);

            return $result->setData([
                'success' => false,
                'message' => (string)__('There is no active cart to attach a note to.'),
            ]);
        }

        try {
            /** @var \Magento\Quote\Model\Quote $quote */
            $quote = $this->cartRepository->get((int)$quoteId);
            $quote->setDeliveryNote($noteVal === '' ? null : $noteVal);
            $this->cartRepository->save($quote);
        } catch (NoSuchEntityException $e) {
            $result->setHttpResponseCode(400);

            return $result->setData([
                'success' => false,
                'message' => (string)__('There is no active cart to attach a note to.'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error(
                'Beersisters_Deliverynote: could not save the delivery note on the quote.',
                ['exception' => $e]
            );
            $result->setHttpResponseCode(500);

            return $result->setData([
                'success' => false,
                'message' => (string)__('The note could not be saved. Please try again.'),
            ]);
        }

        return $result->setData(['success' => true]);
    }
}
