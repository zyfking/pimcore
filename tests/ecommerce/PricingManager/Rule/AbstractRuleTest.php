<?php
/**
 * Created by PhpStorm.
 * User: cfasching
 * Date: 29.03.2018
 * Time: 16:26
 */

namespace Pimcore\Tests\Ecommerce\PricingManager\Rule;

use Codeception\Util\Stub;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\CartPriceCalculator;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\CartPriceModificator\IShipping;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\CartPriceModificator\Shipping;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\ICart;
use Pimcore\Bundle\EcommerceFrameworkBundle\CartManager\SessionCart;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractProduct;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\Currency;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\ICheckoutable;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\AttributePriceInfo;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\AttributePriceSystem;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\IPrice;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\Price;
use Pimcore\Bundle\EcommerceFrameworkBundle\PriceSystem\TaxManagement\TaxEntry;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\Condition\Bracket;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\IAction;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\ICondition;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\IPricingManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\IRule;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\PricingManager;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\PricingManagerLocator;
use Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\Rule;
use Pimcore\Bundle\EcommerceFrameworkBundle\Tools\SessionConfigurator;
use Pimcore\Bundle\EcommerceFrameworkBundle\Type\Decimal;
use Pimcore\Model\DataObject\OnlineShopTaxClass;
use Pimcore\Tests\Helper\Pimcore;
use Pimcore\Tests\Test\EcommerceTestCase;

class AbstractRuleTest extends EcommerceTestCase
{
    /**
     * @return IPricingManager
     *
     * @throws \Codeception\Exception\ModuleException
     */
    protected function buildPricingManager($rules)
    {
        $rules = $this->buildRules($rules);

        /** @var Pimcore $pimcoreModule */
        $pimcoreModule = $this->getModule('\\' . Pimcore::class);
        $container = $pimcoreModule->getContainer();

        $conditionMapping = $container->getParameter('pimcore_ecommerce.pricing_manager.condition_mapping');
        $actionMapping = $container->getParameter('pimcore_ecommerce.pricing_manager.action_mapping');
        $session = $this->buildSession();
        $options = [
            'rule_class' => "Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\Rule",
            'price_info_class' => "Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\PriceInfo",
            'environment_class' => "Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\Environment"
        ];

        $pricingManager = Stub::construct(PricingManager::class, [$conditionMapping, $actionMapping, $session, $options], [
            'getValidRules' => function () use ($rules) {
                return $rules;
            }
        ]);

        return $pricingManager;
    }

    /**
     * @param $value
     *
     * @return IPrice
     *
     * @throws \TypeError
     */
    protected function createPrice($value)
    {
        return new Price(Decimal::create($value), new Currency('EUR'));
    }

    /**
     * @param ICart $cart
     *
     * @return CartPriceCalculator
     */
    protected function buildCartCalculator(ICart $cart, IPricingManager $pricingManager, $withModificators = false)
    {
        $calculator = new CartPriceCalculator($this->buildEnvironment(), $cart);

        if ($withModificators) {
            $shipping = new Shipping(['charge' => 10]);
            $calculator->addModificator($shipping);
        }

        $calculator->setPricingManager($pricingManager);

        return $calculator;
    }

    /**
     * @return ICart
     */
    protected function setUpCart(IPricingManager $pricingManager, $withModificators = false)
    {
        $sessionBag = $this->buildSession()->getBag(SessionConfigurator::ATTRIBUTE_BAG_CART);

        /** @var SessionCart|\PHPUnit_Framework_MockObject_Stub $cart */
        $cart = Stub::construct(SessionCart::class, [], [
            'getSessionBag' => function () use ($sessionBag) {
                return $sessionBag;
            },
            'isCartReadOnly' => function () {
                return false;
            }
        ]);

        $cart->setPriceCalculator($this->buildCartCalculator($cart, $pricingManager, $withModificators));

        return $cart;
    }

    /**
     * @param $id
     * @param $grossPrice
     * @param $pricingManager
     * @param array $categories
     * @param array $taxes
     * @param string $combinationType
     *
     * @return ICheckoutable
     *
     * @throws \TypeError
     */
    protected function setUpProduct(int $id, float $grossPrice, IPricingManager $pricingManager = null, $categories = [], $taxes = [], $combinationType = TaxEntry::CALCULATION_MODE_COMBINE): ICheckoutable
    {
        $grossPrice = Decimal::create($grossPrice);

        $taxClass = new OnlineShopTaxClass();
        $taxEntries = new \Pimcore\Model\DataObject\Fieldcollection();

        foreach ($taxes as $name => $tax) {
            $entry = new \Pimcore\Model\DataObject\Fieldcollection\Data\TaxEntry();
            $entry->setPercent($tax);
            $entry->setName($name);
            $taxEntries->add($entry);
        }
        $taxClass->setTaxEntries($taxEntries);
        $taxClass->setTaxEntryCombinationType($combinationType);

        $environment = $this->buildEnvironment();

        $pricingManagers = Stub::make(PricingManagerLocator::class, [
            'getPricingManager' => function () use ($pricingManager) {
                return $pricingManager;
            }
        ]);

        $priceSystem = Stub::construct(AttributePriceSystem::class, [$pricingManagers, $environment], [
            'getTaxClassForProduct' => function () use ($taxClass) {
                return $taxClass;
            },
            'getPriceClassInstance' => function (Decimal $amount) {
                return new Price($amount, new Currency('EUR'));
            },
            'calculateAmount' => function () use ($grossPrice): Decimal {
                return $grossPrice;
            }
        ]);

        /** @var AbstractProduct|\PHPUnit_Framework_MockObject_Stub $product */
        $product = Stub::construct(AbstractProduct::class, [], [
            'getId' => function () use ($id) {
                return $id;
            },
            'getPriceSystemImplementation' => function () use ($priceSystem) {
                return $priceSystem;
            },
            'getCategories' => function () use ($categories) {
                return $categories;
            }
        ]);

        return $product;
    }

    protected function doAssertions($ruleDefinitions, $productDefinitions, $tests)
    {
        $pricingManager = $this->buildPricingManager($ruleDefinitions);

        $singleProductPrice = $productDefinitions['singleProduct']['price'];

        $priceInfo = new AttributePriceInfo(
            $this->createPrice($singleProductPrice),
            2,
            $this->createPrice($singleProductPrice * 2)
        );

        $priceInfo = $pricingManager->applyProductRules($priceInfo);

        $this->assertTrue($priceInfo->getPrice()->getAmount()->equals(Decimal::create($tests['productPriceSingle'])), 'check single product price: ' . $priceInfo->getPrice()->getAmount() . ' vs. ' . $tests['productPriceSingle']);
        $this->assertTrue($priceInfo->getTotalPrice()->getAmount()->equals(Decimal::create($tests['productPriceTotal'])), 'check total product price: ' . $priceInfo->getTotalPrice()->getAmount() . ' vs. ' . $tests['productPriceTotal']);

        $product = $this->setUpProduct($productDefinitions['singleProduct']['id'], $productDefinitions['singleProduct']['price'], $pricingManager);
        $this->assertTrue($product->getOSPrice()->getAmount()->equals(Decimal::create($tests['productPriceSingle'])), 'check single product price via product object');

        $cart = $this->setUpCart($pricingManager, false);
        foreach ($productDefinitions['cart'] as $cartProduct) {
            $cart->addItem($this->setUpProduct($cartProduct['id'], $cartProduct['price'], $pricingManager), 1);
        }

        $this->assertTrue($cart->getPriceCalculator()->getSubTotal()->getAmount()->equals(Decimal::create($tests['cartSubTotal'])), 'check cart subtotal price: ' . $cart->getPriceCalculator()->getSubTotal()->getAmount() . ' vs ' . $tests['cartSubTotal']);
        $this->assertTrue($cart->getPriceCalculator()->getGrandTotal()->getAmount()->equals(Decimal::create($tests['cartGrandTotal'])), 'check cart total price: ' . $cart->getPriceCalculator()->getGrandTotal()->getAmount() . ' vs ' . $tests['cartGrandTotal']);

        $cart = $this->setUpCart($pricingManager, true);
        foreach ($productDefinitions['cart'] as $cartProduct) {
            $cart->addItem($this->setUpProduct($cartProduct['id'], $cartProduct['price'], $pricingManager), 1);
        }
        $this->assertTrue($cart->getPriceCalculator()->getSubTotal()->getAmount()->equals(Decimal::create($tests['cartSubTotalModificators'])), 'check cart with modificators subtotal price: ' . $cart->getPriceCalculator()->getSubTotal()->getAmount() . ' vs ' . $tests['cartSubTotalModificators']);
        $this->assertTrue($cart->getPriceCalculator()->getGrandTotal()->getAmount()->equals(Decimal::create($tests['cartGrandTotalModificators'])), 'check cart with modificators total price: ' . $cart->getPriceCalculator()->getGrandTotal()->getAmount() . ' vs ' . $tests['cartGrandTotalModificators']);

        if (array_key_exists('giftItemCount', $tests)) {
            $this->assertEquals(count($cart->getGiftItems()), $tests['giftItemCount'], 'check gift item count: ' . count($cart->getGiftItems()) . ' vs. ' . $tests['giftItemCount']);
        }

        return $cart;
    }

    protected function doAssertionsWithGiftItem($ruleDefinitions, $productDefinitions, $tests, $hasGiftItem)
    {
        $this->doAssertions($ruleDefinitions, $productDefinitions, $tests);

        $pricingManager = $this->buildPricingManager($ruleDefinitions);

        $cart = $this->setUpCart($pricingManager, true);
        foreach ($productDefinitions['cart'] as $cartProduct) {
            $cart->addItem($this->setUpProduct($cartProduct['id'], $cartProduct['price'], $pricingManager), 1);
        }

        $giftItems = $cart->getGiftItems();

        if ($hasGiftItem) {
            $this->assertTrue(count($giftItems) > 0, 'Check if Cart has gift items - it should have');
        } else {
            $this->assertTrue(count($giftItems) == 0, 'Check if Cart has gift items - it should not have.');
        }
    }

    protected function doAssertionsWithShippingCosts($ruleDefinitions, $productDefinitions, $tests, $noShippingCosts)
    {
        $this->doAssertions($ruleDefinitions, $productDefinitions, $tests);

        $pricingManager = $this->buildPricingManager($ruleDefinitions);

        $cart = $this->setUpCart($pricingManager, true);
        foreach ($productDefinitions['cart'] as $cartProduct) {
            $cart->addItem($this->setUpProduct($cartProduct['id'], $cartProduct['price'], $pricingManager), 1);
        }

        $modifications = $cart->getPriceCalculator()->getPriceModifications();

        if ($noShippingCosts) {
            $this->assertTrue($modifications['shipping']->getAmount()->equals(Decimal::create(0)), 'Check if cart has shipping costs - it should have');
        } else {
            $this->assertFalse($modifications['shipping']->getAmount()->equals(Decimal::create(0)), 'Check if cart has shipping costs - it should not have.');
        }
    }

    protected function getShippingModificator($modificators)
    {
        foreach ($modificators as $modificator) {
            if ($modificator instanceof  IShipping) {
                return $modificator;
            }
        }

        return $modificator;
    }

    /**
     * @param $actionDefinitions
     *
     * @return IAction[]
     */
    protected function buildActions($definitions)
    {
        $elements = [];
        foreach ($definitions as $definition) {
            $element = new $definition['class']();

            foreach ($definition as $key => $value) {
                if ($key == 'class') {
                    continue;
                }
                $setter = 'set' . ucfirst($key);
                $element->$setter($value);
            }

            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * @param $conditionDefinitions
     *
     * @return ICondition
     */
    protected function buildConditions($conditionDefinitions)
    {
        if ($conditionDefinitions instanceof ICondition) {
            return $conditionDefinitions;
        }

        if (is_string($conditionDefinitions)) {
            return unserialize($conditionDefinitions);
        }

        if (is_array($conditionDefinitions)) {
            if ($conditionDefinitions['class'] == Bracket::class) {
                $condition = new Bracket();
                foreach ($conditionDefinitions['conditions'] as $subCondition) {
                    $condition->addCondition($this->buildConditions($subCondition['condition']), $subCondition['operator']);
                }

                return $condition;
            } else {
                $condition = new $conditionDefinitions['class']();
                foreach ($conditionDefinitions as $key => $value) {
                    if ($key == 'class') {
                        continue;
                    }
                    $setter = 'set' . ucfirst($key);
                    $condition->$setter($value);
                }

                return $condition;
            }
        }
    }

    /**
     * @param $ruleDefinitions
     *
     * @return IRule[]
     */
    protected function buildRules($ruleDefinitions)
    {
        $rules = [];

        foreach ($ruleDefinitions as $name => $ruleDefinition) {
            $rule = new Rule();
            $rule->setName($name);
            $rule->setActive(true);
            $rule->setActions($this->buildActions($ruleDefinition['actions']));

            $condition = $this->buildConditions($ruleDefinition['condition']);
            if ($condition) {
                $rule->setCondition($condition);
            }

//            if($ruleDefinition['condition']) {
//                $rule->setValue("condition", $ruleDefinition['condition']);
//            }

            $rules[] = $rule;
        }

        return $rules;
    }
}
