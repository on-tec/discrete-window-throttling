<?php

if(method_exists(\Carbon\CarbonInterval::class, 'enableFloatSetters'))
	\Carbon\CarbonInterval::enableFloatSetters(true); // Carbon 3.0 feature.

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(\Ontec\Throttling\DiscreteWindowTests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
	return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function steps(int $factor = 1): int {
	return intval($_ENV['TIME_STEP']) * $factor;
}

function usleep_carbon(\Carbon\Carbon|\Carbon\CarbonInterval|null $eta): void {
	if($eta === null)
		return;
	if($eta instanceof Carbon)
		$eta = Carbon::now()->diff($eta);
	if(($u = round($eta->totalMicroseconds)) <= 0)
		return;
	usleep(intval($u));
}
