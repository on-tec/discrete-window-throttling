<?php

namespace Ontec\Throttling\DiscreteWindowTests;

// use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
	protected function setUp(): void {
		parent::setUp();

		app(\Illuminate\Contracts\Redis\Factory::class)->connection()
			->function('LOAD', 'REPLACE', file_get_contents(__DIR__.'/../throttling.lua'));
	}

	protected function getPackageProviders($app): array {
		return [
			\Ontec\Throttling\DiscreteWindowServiceProvider::class,
			\Illuminate\Redis\RedisServiceProvider::class,
		];
	}
}
