<?php

class DiscountCalculator
{
	const DISCOUNT_TYPE_GROUP = 'group';
	const DISCOUNT_TYPE_EACH = 'each';
	const DISCOUNT_TYPE_ORDER = 'order';

	const RULE_EXCLUDE = 'exclude';
	const RULE_SELECT = 'select';

	const PERCENT_GRADATION = 'gradation';
	const PERCENT_VALUE = 'value';

	const TYPE_KEY = 'type';

	/**
	 * Цены на продукты (char => int)
	 *
	 * @var array
	 */
	private $productPrices;

	/**
	 * Скидки
	 *
	 * @var array
	 */
	private $discounts;

	/**
	 * @param array $productPrices
	 * @param array $discounts
	 */
	public function __construct(array $productPrices, array $discounts)
	{
		$this->productPrices = $productPrices;
		$this->discounts = $discounts;
	}

	/**
	 * @param array $orderedProducts - заказанные (выбранные) продукты
	 * @return float
	 */
	public function calculateOrderTotal(array $orderedProducts)
	{
		$totalPrice = 0;
		$orderDiscounts = [];
		foreach ($this->discounts as $discountName => $discountData) {
			if (!is_null($discountType = $this->getDiscountType($discountData))
				&& !is_null($selectedProducts = $this->getProductsForDiscount($orderedProducts, $discountData))
				&& !is_null($discountPercent = $this->getDiscountPercent($selectedProducts, $discountData))) {

				$discountMultiplier = $this->percentToMultiplier($discountPercent);

				// если скидка для заказа - добавим скидку в список скидок заказа
				if (self::DISCOUNT_TYPE_ORDER === $discountType) {
					array_push($orderDiscounts, $discountMultiplier);
				} else {
					// вычтем выбранные продукты
					foreach ($selectedProducts as $productType => $productAmount) {
						$orderedProducts[$productType] -= $productAmount;
					}
					$totalPrice += $this->calculateTotalPrice($selectedProducts, $discountType, $discountMultiplier);
				}
			}
		}

		// цена оставшихся продуктов
		$totalPrice += $this->calculateTotalPrice($orderedProducts);

		// применение скидки на заказ
		foreach ($orderDiscounts as $discountMultiplier) {
			$totalPrice = $this->formatPrice($totalPrice * $discountMultiplier);
		}

		return $totalPrice;
	}

	/**
	 * Подсчёт цены продуктов учитывая тип скидки
	 *
	 * @param array $products
	 * @param string|null $discountType
	 * @param float $discountMultiplier
	 *
	 * @return float
	 */
	private function calculateTotalPrice(array $products, $discountType = null, $discountMultiplier = 1.)
	{
		if (self::DISCOUNT_TYPE_GROUP === $discountType) {

			// применить для каждой пары/тройки/... неполные комбинации не учитываются
			$price = array_reduce(array_keys($products), function ($price, $productType) {
				return $price + $this->productPrices[$productType];
			}, 0);
			$totalPrice = $this->formatPrice($discountMultiplier * $price) * min($products);
		} else {

			// для каждого продукта
			$totalPrice = array_reduce(array_keys($products), function ($price, $productType) use ($products, $discountMultiplier) {
				$productTotalPrice = $products[$productType] * $this->formatPrice($this->productPrices[$productType] * $discountMultiplier);
				return $price + $productTotalPrice;
			}, 0);
		}

		return $totalPrice;
	}

	/**
	 * @param float $price
	 *
	 * @return float|int
	 */
	private function formatPrice($price)
	{
		return round($price, 2);
	}

	/**
	 * @param int $percent
	 *
	 * @return float|int
	 */
	private function percentToMultiplier($percent)
	{
		return (100 - $percent) / 100;
	}

	/**
	 * @param array $discountData
	 *
	 * @return string|null
	 */
	private function getDiscountType(array $discountData)
	{
		if (array_key_exists($key = self::TYPE_KEY, $discountData)) {
			$discountType = $discountData[$key];
		} else {
			$discountType = null;
		}

		return $discountType;
	}

	/**
	 * Получает процент скидки определённому значению (PERCENT_VALUE), либо вычисляет по количеству продуктов (PERCENT_GRADATION)
	 *
	 * @param array $products
	 * @param array $discountData
	 *
	 * @return int
	 */
	private function getDiscountPercent(array $products, array $discountData)
	{
		if (array_key_exists($valueType = self::PERCENT_GRADATION, $discountData)) {
			$discountPercent = $this->calculateTotalDiscount($products, $discountData[$valueType]);
		} elseif (array_key_exists($valueType = self::PERCENT_VALUE, $discountData)) {
			$discountPercent = $discountData[$valueType];
		} else {
			$discountPercent = null;
		}

		return $discountPercent;
	}

	/**
	 * Вычисление процента скидки по количеству заказанных продуктов
	 *
	 * @param array $selectedProducts
	 * @param array $gradation
	 *
	 * @return int|null
	 */
	private function calculateTotalDiscount(array $selectedProducts, array $gradation)
	{
		$totalAmount = array_reduce($selectedProducts, function ($totalAmount, $productAmount) {
			return $totalAmount + $productAmount;
		}, 0);

		$discountPercent = null;
		reset($gradation);
		while (!is_null($minimumAmount = key($gradation)) && $minimumAmount <= $totalAmount) {
			$discountPercent = current($gradation);
			next($gradation);
		}

		return $discountPercent;
	}

	/**
	 * Выборка продуктов для применения скидки
	 *
	 * @uses getProductsByExcludeRule
	 * @uses getProductsBySelectRule
	 *
	 * @param array $products
	 * @param array $discountData
	 *
	 * @return array|null
	 */
	private function getProductsForDiscount(array $products, array $discountData)
	{
		$methodForRule = [
			self::RULE_EXCLUDE => 'getProductsByExcludeRule',
			self::RULE_SELECT => 'getProductsBySelectRule',
		];
		foreach (array_intersect_key($methodForRule, $discountData) as $ruleType => $method) {
			$products = call_user_func([$this, $method], $products, $discountData[$ruleType]);
		}

		return 0 !== count($products) ? $products : null;
	}

	/**
	 * Выборка продуктов по правилу 'select'
	 *
	 * @param array $availableProducts
	 * @param array $productTypeGroups
	 *
	 * @return array
	 */
	private function getProductsBySelectRule(array $availableProducts, array $productTypeGroups)
	{
		$selectedProducts = [];
		foreach ($productTypeGroups as $productTypes) {
			$matchedProducts = array_intersect_key($availableProducts, array_flip($productTypes));

			// первый продукт - с максимальным количеством
			arsort($matchedProducts);
			if (($productAmount = reset($matchedProducts))) {
				$productType = key($matchedProducts);
				$selectedProducts[$productType] = $productAmount;
			}
		}

		// если в каждую группу выбрано по продукту - возьмём максимально возможное число продуктов с одинаковым количеством
		if (count($productTypeGroups) === count($selectedProducts)) {
			$minimumAmount = min($selectedProducts);
			array_walk($selectedProducts, function (&$productAmount) use ($minimumAmount) {
				$productAmount = $minimumAmount;
			});
		} else {
			$selectedProducts = [];
		}

		return $selectedProducts;
	}

	/**
	 * Выборка продуктов по правилу 'exclude'
	 *
	 * @param array $availableProducts
	 * @param array $productTypeGroups
	 *
	 * @return array
	 */
	private function getProductsByExcludeRule(array $availableProducts, array $productTypeGroups)
	{
		return array_diff_key($availableProducts, array_flip($productTypeGroups));
	}
}

/**
 * Цены на продукты
 *
 * type => price
 */
$productPrices = [
	'A' => 100,
	'B' => 10,
	'C' => 50,
	'D' => 10,
	'E' => 30,
	'F' => 20,
	'G' => 10,
	'H' => 350,
	'I' => 950,
	'J' => 45,
	'K' => 15,
	'L' => 5,
	'M' => 40,
];

/**
 * Скидки
 */
$discounts = [
	'выбраны А и B, то их суммарная стоимость уменьшается на 10% (для каждой пары А и B)' => [
		DiscountCalculator::RULE_SELECT => [['A'],['B']],
		DiscountCalculator::TYPE_KEY => DiscountCalculator::DISCOUNT_TYPE_GROUP,
		DiscountCalculator::PERCENT_VALUE => 10,
	],
	'выбраны D и E, то их суммарная стоимость уменьшается на 5% (для каждой пары D и E)' => [
		DiscountCalculator::RULE_SELECT => [['D'],['E']],
		DiscountCalculator::TYPE_KEY => DiscountCalculator::DISCOUNT_TYPE_GROUP,
		DiscountCalculator::PERCENT_VALUE => 5,
	],
	'выбраны E, F, G, то их суммарная стоимость уменьшается на 5% (для каждой тройки E, F, G)' => [
		DiscountCalculator::RULE_SELECT => [['E'],['F'],['G']],
		DiscountCalculator::TYPE_KEY => DiscountCalculator::DISCOUNT_TYPE_GROUP,
		DiscountCalculator::PERCENT_VALUE => 5,
	],
	'выбраны А и один из [K, L, M], то стоимость выбранного продукта уменьшается на 5%' => [
		DiscountCalculator::RULE_SELECT => [['A'],['K','L','M']],
		DiscountCalculator::TYPE_KEY => DiscountCalculator::DISCOUNT_TYPE_EACH,
		DiscountCalculator::PERCENT_VALUE => 5,
	],
	'выбрал одновременно 5/4/3 продуктов, он получает скидку 20%/10%/5% от суммы заказа, A и C не участвуют' => [
		DiscountCalculator::RULE_EXCLUDE => ['A','C'],
		DiscountCalculator::TYPE_KEY => DiscountCalculator::DISCOUNT_TYPE_ORDER,
		DiscountCalculator::PERCENT_GRADATION => [
			3 => 5,
			4 => 10,
			5 => 20,
		],
	],
];

/**
 * Заказанные (выбранные) продукты
 *
 * type => amount
 */
$orderedProducts = [
	'A' => 7,
	'B' => 2,
	'C' => 1,
	'D' => 1,
	'E' => 1,
	'J' => 1,
	'K' => 4,
	'L' => 1,
	'M' => 1,
];

$discountCalculator = new DiscountCalculator($productPrices, $discounts);
$orderTotal = $discountCalculator->calculateOrderTotal($orderedProducts);

echo sprintf('Сумма заказа: %.2f', $orderTotal) . "\n\n";
