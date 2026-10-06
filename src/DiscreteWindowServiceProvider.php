<?php

namespace Ontec\Throttling;

use Illuminate\Support\Arr;

class DiscreteWindowServiceProvider extends \Illuminate\Support\ServiceProvider
{
	public function boot(): void {
		if(method_exists(\Carbon\CarbonInterval::class, 'enableFloatSetters'))
			\Carbon\CarbonInterval::enableFloatSetters(true); // Carbon 3.0 feature.

		$this->publishes([
			__DIR__.'/../throttling.lua' => resource_path('redis/functions/discrete_window_throttling.lua'),
		], 'redis-functions');
	}

	public function register(): void {
		$this->app->bind(DiscreteWindow::class, function($app, array $params) {
			if(!isset($params['connection']))
				$params['connection'] = $app->make('redis')->connection();

			$class = new \ReflectionClass(DiscreteWindow::class);
			$whitelist = array_map(fn(\ReflectionParameter $p) => $p->getName(),
				$class->getConstructor()?->getParameters() ?? []);
			return new DiscreteWindow(...Arr::only($params, $whitelist));
		});
	}
}
