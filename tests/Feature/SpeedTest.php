<?php

use Ontec\Throttling\DiscreteWindow;
use Carbon\Carbon;
use Carbon\CarbonInterval;

const FRAME_COUNT = 1000;
const FRAME_LENGTH = 2000;
const FRAME_WIDTH = 16;

beforeEach(function() {
    $this->throttler = app()->make(DiscreteWindow::class, [
		'frame_count' => FRAME_COUNT,
		'frame_length' => CarbonInterval::microseconds(FRAME_LENGTH),
		'frame_width' => FRAME_WIDTH,
	]);
	$this->redis = invade($this->throttler)->connection;
	$this->log = app('log');
});

const CYCLES = 100;
const GATEWAYS = 1000;
const LIMIT = 10;

/**
 * Total test duration scales primarily by CYCLES, secondary by GATEWAYS and FRAME_COUNT.
 * Total used memory scales primarily by GATEWAYS, secondary by FRAME_WIDTH and FRAME_COUNT.
 * Adjust CYCLES, GATEWAYS, FRAME_LENGTH and LIMIT for your hardware to get approximately 50%/50% success/denials in result.
 */
test('Fluid with denials and expiration', function($prefix) {
	$memory = $this->redis->info('memory');
	$this->log->info('Redis memory usage (before): '.$memory['used_memory_human'].' (peak '.$memory['used_memory_peak_human'].').');

	$window = CarbonInterval::microseconds(FRAME_LENGTH * FRAME_COUNT);
	$this->log->info('Making '.(CYCLES * GATEWAYS).' requests. Window: '.$window->totalSeconds.'s. Frames: '.FRAME_COUNT.'.');

	list($limit, $success, $denials) = [LIMIT, 0, 0];
	for($cycle = 0; $cycle < CYCLES; $cycle++) {
		for($id = 0; $id < GATEWAYS; $id++) {
			$r = $this->throttler->tryLog($key = $prefix.':'.$id, 1, $limit);
			if($r->taken > 0) {
				$denials > 0 and $limit > 1 and $limit--; // Increase on success.
				$success++;
			} else {
				$denials > 0 and $limit++; // Decrease on denial.
				$denials++;
			}
		}

		if($cycle % (CYCLES / 10) == 0)
			$this->log->debug('...'.round($cycle / CYCLES * 100).'%: Current limit: '.$limit.'.');
	}

	$this->log->info('Completed. Writes: '.$success.'. Denials: '.$denials.'.');

	$memory = $this->redis->info('memory');
	$this->log->info('Redis memory usage (after): '.$memory['used_memory_human'].' (peak '.$memory['used_memory_peak_human'].').');

	expect($success + $denials)->toBe(CYCLES * GATEWAYS);
})->with(['test:performance:'])->skip(!env('BENCHMARK'), 'Use a special command for performance testing.');
