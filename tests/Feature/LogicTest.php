<?php
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Ontec\Throttling\DiscreteWindow;
use Carbon\Carbon;
use Carbon\CarbonInterval;

/**
 * @property DiscreteWindow $throttler
 */
beforeEach(function() {
    $this->throttler = app()->make(DiscreteWindow::class, [
		'frame_count' => 10,
		'frame_length' => CarbonInterval::microseconds(steps(1)),
		// 'frame_width' => 8,
		'default_limit' => 10
	]);
	$this->throttler->relative_eta = true;
});

test('Date & time synchronization', function() {
	$ts1 = Carbon::now()->getPreciseTimestamp(6);
	$ts2 = $this->throttler->timestamp();
	$ts3 = Carbon::now()->getPreciseTimestamp(6);
	expect($ts2)->toBeBetween($ts1, $ts3);
});

test('Recording and expiration', function($key) {
	$this->throttler->clear($key);
	expect($this->throttler->log($key, 1))->toBe(1);
	usleep(steps(3));
	expect($this->throttler->log($key, 2))->toBe(3);
	usleep(steps(3));
	expect($this->throttler->log($key, 3))->toBe(6);
	usleep(steps(6)); // Waiting 4 remaining frames + 1 extra frame + 1 frame to make sure.
	expect($this->throttler->log($key, 0))->toBe(5); // First counter should be expired by now.
	usleep(steps(5)); // Waiting remaining half of window.
	expect($this->throttler->log($key, 1))->toBe(1); // All original counters should be expired by now.
})->with(['test:recording_and_expiration']);

test('Overflow and release', function($key) {
	$this->throttler->clear($key);
	expect($this->throttler->tryLog($key, 5))->toHaveProperty('taken', 5); // Success.
	usleep(steps(3));
	expect($this->throttler->tryLog($key, 5))->toHaveProperty('taken', 5); // Success.
	usleep(steps(3));

	expect($result = $this->throttler->tryLog($key, 5))->toHaveProperty('taken', 0); // Doesn't fit yet.
	expect($result->eta?->totalMicroseconds)->toBeBetween(steps(3), steps(6));
	usleep_carbon($result->eta);

	expect($this->throttler->tryLog($key, 5))->toHaveProperty('taken', 5); // Now success.
	expect($result = $this->throttler->tryLog($key, 6))->toHaveProperty('taken', 0); // Expiration of both elements is required.
	expect($result->eta?->totalMicroseconds)->toBeBetween(steps(10 - 1), steps(20)); // Whole window of waiting.
})->with(['test:overflow_and_release']);

test('Partial filling', function($key) {
	$this->throttler->clear($key);
	expect($this->throttler->fillLog($key, 4))->toHaveProperty('taken', 4); // Success. Total = 4.
	usleep(steps(3));
	expect($this->throttler->fillLog($key, 4))->toHaveProperty('taken', 4); // Success. Total = 8.
	usleep(steps(3));

	expect($result = $this->throttler->fillLog($key, 4))->toHaveProperty('taken', 2); // 2 taken, 2 left. Total = 10.
	expect($result->eta?->totalMicroseconds)->toBeBetween(steps(3), steps(6));
	usleep_carbon($result->eta); // Total = 6.

	expect($this->throttler->fillLog($key, 3))->toHaveProperty('taken', 3); // 3 taken. Total = 9.
	expect($result = $this->throttler->fillLog($key, 8))->toHaveProperty('taken', 1); // 1 taken, 7 left. Total = 10.
	usleep_carbon($result->eta); // Total = 1.
	expect($this->throttler->fillLog($key, 9))->toHaveProperty('taken', 9); // 9 taken. Total = 10.
})->with(['test:partial_filling']);

test('Read only ETA', function($key) {
	$this->throttler->relative_eta = false;
	$this->throttler->clear($key);
	expect($this->throttler->log($key, 5))->toBe(5);
	expect($this->throttler->etaFor($key, 5))->toBeLessThanOrEqual(now());
	expect($this->throttler->etaFor($key, 6))->toBeGreaterThan(now()->addMicroseconds(steps(10 - 1))); // Whole window of waiting.
	usleep(steps(5));
	expect($this->throttler->etaFor($key, 6))->toBeLessThan(now()->addMicroseconds(steps(5 + 1 + 1))); // Half of time window.
})->with(['test:read_only_eta']);

// test('', function($key) {
// 	$this->throttler->relative_eta = false; $format = 'H:i:s.u';
// 	$pre = now(); $eta = $this->throttler->tryLog(...)->eta;
// 	dump($pre->format($format).' < '.now()->format($format).' < '.$eta->format($format),
// 		$pre->diff($eta, false)->totalMicroseconds .' > '. now()->diff($eta, false)->totalMicroseconds);
// })->with(['test']);
