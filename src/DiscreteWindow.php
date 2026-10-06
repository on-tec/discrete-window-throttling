<?php

namespace Ontec\Throttling;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Redis\Connection;

class DiscreteWindow
{
	public const LUA_MAX_SAFE_INTEGER = 2**53 - 1;

	public readonly int $frame_length;
	public readonly int $frame_width;
	public int $default_limit;
	public bool $relative_eta = false;

	public function __construct(public readonly Connection $connection,
								public readonly int $frame_count,
								CarbonInterval $frame_length,
								int $frame_width = 0,
								int $default_limit = 0) {
		if($frame_count < 1)
			throw new \InvalidArgumentException('At least single frame must be provided.');
		$this->frame_length = intval(ceil($frame_length->totalMicroseconds));
		if($this->frame_length <= 0)
			throw new \InvalidArgumentException('Time frame duration can not be empty.');
		if($this->frame_length * ($frame_count + 1) > self::LUA_MAX_SAFE_INTEGER)
			throw new \InvalidArgumentException('Time window is too big for Lua integer arithmetics.');
		if($frame_width <= 0 && $default_limit <= 0)
			throw new \InvalidArgumentException('Either frame width or default limit must be provided.');
		if($frame_width <= 0)
			$frame_width = intval(ceil(log($default_limit + 1, 2))); // Calculating automatically.
		if(2 ** $frame_width - 1 > self::LUA_MAX_SAFE_INTEGER)
			throw new \InvalidArgumentException('Frame width is too big for Lua integer arithmetics.');
		if($default_limit > self::LUA_MAX_SAFE_INTEGER)
				throw new \InvalidArgumentException('Limit is too big for Lua integer arithmetics.');

		$this->frame_width = $frame_width;
		$this->default_limit = $default_limit;
	}

	/**
	 * Writes events to gateway without performing any limit checks.
	 * @internal Use one of atomic functions which supports limit check instead.
	 * @param string $key Custom gateway identifier.
	 * @param string $event_count Number of the events to write.
	 * @return int Sum of all events currently stored in time window.
	 */
	public function log(string $key, int $event_count = 1): int {
		$this->validateEventCount($event_count, $this->default_limit ?: self::LUA_MAX_SAFE_INTEGER);
		$r = $this->connection->fcall('dwthrottler_events', [$key], [
			$this->frame_count, $this->frame_length, $this->frame_width,
			$event_count
		]);
		return $r[0];
	}

	public function tryLog(string $key, int $event_count = 1, int $limit = 0) {
		$this->validateEventCount($event_count, $limit = $limit ?: $this->default_limit);
		$r = $this->connection->fcall('dwthrottler_try', [$key], [
			$this->frame_count, $this->frame_length, $this->frame_width,
			$limit, $event_count
		]);
		$taken = intval($r[0]);
		$eta = $taken == $event_count ? null : $this->parseETA($r[1]);
		return new readonly class($taken, $eta) {
			public function __construct(public int $taken, public Carbon|CarbonInterval|null $eta) { }
		};
	}

	public function fillLog(string $key, int $event_count = 1, int $limit = 0) {
		$this->validateEventCount($event_count, $limit = $limit ?: $this->default_limit);
		$r = $this->connection->fcall('dwthrottler_fill', [$key], [
			$this->frame_count, $this->frame_length, $this->frame_width,
			$limit, $event_count
		]);
		$taken = intval($r[0]);
		$eta = $taken == $event_count ? null : $this->parseETA($r[1]);
		return new readonly class($taken, $eta) {
			public function __construct(public int $taken, public Carbon|CarbonInterval|null $eta) { }
		};
	}

	public function etaFor(string $key, int $event_count = 1, int $limit = 0): Carbon|CarbonInterval {
		$this->validateEventCount($event_count, $limit = $limit ?: $this->default_limit);
		$timestamp = $this->connection->fcall_ro('dwthrottler_eta', [$key], [
			$this->frame_count, $this->frame_length, $this->frame_width,
			$limit, $event_count
		]);
		return $this->parseETA($timestamp);
	}

	public function clear(string $key): static {
		$this->connection->del($key);
		return $this;
	}

	public function timestamp(): int {
		return intval(round($this->connection->fcall_ro('dwthrottler_now')));
	}

	protected function validateEventCount(int $event_count, int $limit) {
		if($event_count < 0 || $event_count > $limit || $event_count > self::LUA_MAX_SAFE_INTEGER)
			throw new \InvalidArgumentException('Events count is out of meaningful range.');
	}

	protected function parseETA(int $timestamp): Carbon|CarbonInterval {
		$eta = \Carbon\Carbon::createFromTimestampMs($timestamp / 1000);
		return $this->relative_eta ? now()->diff($eta, false)->cascade() : $eta; // Notice: $a->diff($b) ≡ ($b - $a) by specs.
	}
}
