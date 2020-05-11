<?php

namespace nlog\farm;

use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\Crops;
use pocketmine\block\Stem;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockGrowEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\math\Facing;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;

class AlwaysFarm extends PluginBase implements Listener {

	private const DEFAULT_SETTING
		= [
			"WHEAT_BLOCK"    => 1800,
			"CARROT_BLOCK"   => 1800,
			"POTATOES"       => 1800,
			"CARROTS"        => 1800,
			"BEETROOT_BLOCK" => 1800,
			"PUMPKIN_STEM"   => 1800,
			"MELON_STEM"     => 1800
		];

	private const PROCESSING_COUNT_PER_SECOND = 700;

	private static function hash(Position $position) {
		assert($position->getWorld() instanceof World);
		return "{$position->getFloorX()}.{$position->getFloorY()}.{$position->getFloorZ()}.{$position->getWorld()->getFolderName()}";
	}

	private static function un_hash(string $str): Position {
		return new Position(...array_values(array_map(function ($a) {
			if (!is_numeric($a)) {
				return Server::getInstance()->getWorldManager()->getWorldByName($a);
			}
			return (int)$a;
		}, (explode(".", $str)))));
	}

	private static function chunkHash(Chunk $chunk) {
		return "{$chunk->getX()}.{$chunk->getZ()}";
	}

	private static function getAge(Crops $item): int {
		$r = new \ReflectionClass($item);
		$f = $r->getProperty("age");
		$f->setAccessible(true);
		return $f->getValue($item);
	}

	private static function invoke($class, $methodName) {
		$r = new \ReflectionClass($class);
		$f = $r->getMethod($methodName);
		$f->setAccessible(true);
		return $f->invoke($class);
	}

	/** @var int[] */
	private $data = [];

	/** @var world=>chunk_hash=>xyz=[block_id, int] */
	private $farm = [];

	/** @var array[] */
	private $queue = [];

	public function onEnable(): void {
		$this->saveResource('setting.json');
		$this->saveResource('farm.json');
		$this->saveResource('queue.json');

		$data = [];
		try {
			$data = json_decode(file_get_contents($this->getDataFolder() . "setting.json"), true);
			assert(is_array($data));
			array_merge_recursive($data, self::DEFAULT_SETTING);
		} catch (\Throwable $e) {
			$data = self::DEFAULT_SETTING;
		} finally {
			foreach (self::DEFAULT_SETTING as $k => $v) {
				$data[$k] = is_int($data[$k] ?? null) ? $data[$k] : self::DEFAULT_SETTING[$k];
			}
		}

		$this->data = $data;

		$r = new \ReflectionClass(BlockLegacyIds::class);
		foreach ($this->data as $str => $value) {
			if (is_int(($res = $r->getConstant($str)))) {
				$this->data[$res] = $value;
			}
		}

		$this->farm = json_decode(file_get_contents($this->getDataFolder() . "farm.json"), true) ?? [];
		$this->queue = json_decode(file_get_contents($this->getDataFolder() . "queue.json"), true) ?? [];

		$this->getServer()->getPluginManager()->registerEvent(ChunkLoadEvent::class, function (ChunkLoadEvent $event) {
			if (isset($this->farm[$worldName = $event->getWorld()->getFolderName()][$hash = AlwaysFarm::chunkHash($event->getChunk())])) {
				foreach ($this->farm[$worldName][$hash] as $xyz => $data) {
					$this->setBlock(Position::fromObject(self::un_hash($xyz), $event->getWorld()), $data, true);
				}
			}
		}, EventPriority::MONITOR, $this, true);

		$this->getServer()->getPluginManager()->registerEvent(BlockPlaceEvent::class, function (BlockPlaceEvent $event) {
			$this->removeFarm($pos = $event->getBlock()->getPos());
			$this->addFarm($pos, $event->getBlock());
			//$this->sort();
		}, EventPriority::MONITOR, $this, false);

		$this->getServer()->getPluginManager()->registerEvent(BlockBreakEvent::class, function (BlockBreakEvent $event) {
			if (!$event->getBlock()->isSameType(VanillaBlocks::PUMPKIN()) && !$event->getBlock()->isSameType(VanillaBlocks::MELON())) {
				return;
			}
			if ($event->getBlock()->getPos()->getWorld()->getBlock($event->getBlock()->getPos())->getId() !== 0) return;
			if (!$event->isCancelled()) return;
			$this->removeFarm($pos = $event->getBlock()->getPos());
			foreach ($event->getBlock()->getHorizontalSides() as $_ => $block) {
				if ($this->existFarm($block) && $block instanceof Stem) {
					$this->queue[$hash = self::hash($block->getPos())] = $this->getFarm($block);
					$this->queue[$hash][2] = time() + mt_rand(7 * 60, 60 * 17);

					$this->setFarmData($block->getPos(), $this->queue[$hash]);
				}
			}
			//$this->sort();
		}, EventPriority::MONITOR, $this, true);

		$this->getServer()->getPluginManager()->registerEvent(BlockGrowEvent::class, function (BlockGrowEvent $event) {
			if ((new \ReflectionClass($event))->getShortName() === "BlockGrowEvent") {
				$event->setCancelled(true);
			}
		}, EventPriority::LOWEST, $this, false);

		//$this->sort();

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (int $currentTick): void {
			$skip = [];
			$cnt = count($this->queue);
			for ($i = 0; $i < min(self::PROCESSING_COUNT_PER_SECOND, $cnt); $i++) {
				if (isset($skip[$k = array_key_first($this->queue)])) {
					return;
				}
				$skip[$k] = 1;
				$this->setBlock(self::un_hash($k), array_shift($this->queue));
			}
			//var_dump($this->farm, $this->queue);
		}), 20);
	}

	public function save() {
		file_put_contents($this->getDataFolder() . "queue.json", json_encode($this->queue));
		file_put_contents($this->getDataFolder() . "farm.json", json_encode($this->farm));
	}

	protected function onDisable() {
		$this->save();
	}

	/*private function sort(): void {
		uksort($this->queue, function ($a, $b) {
			$a = $this->queue[$a];
			$b = $this->queue[$b];
			return $this->data[$a[0]] - time() + $a[1] < $this->data[$b[0]] - time() + $b[1];
		});
	}*/

	private function removeFarm(Position $position): void {
		$hash = self::hash($position);
		$chunk_hash = self::chunkHash($position->getWorld()->getChunkAtPosition($position));
		if (isset($this->queue[$hash])) {
			unset($this->queue[$hash]);
		}
		if (isset($this->farm[$position->getWorld()->getFolderName()][$chunk_hash][$hash])) {
			unset ($this->farm[$position->getWorld()->getFolderName()][$chunk_hash][$hash]);
		}
	}

	private function setFarmData(Position $pos, array $data): void {
		$hash = self::hash($pos);
		$chunk_hash = self::chunkHash($pos->getWorld()->getChunkAtPosition($pos));
		if (isset($this->queue[$hash])) {
			$this->queue[$hash] = $data;
		}

		$this->farm[$pos->getWorld()->getFolderName()][$chunk_hash][$hash] = $data;
	}

	private function existFarm(Block $block): bool {
		return isset($this->data[$block->getId()])
			&& isset($this->farm[($world = ($p = $block->getPos())->getWorld())->getFolderName()][self::chunkHash($world->getChunkAtPosition($p))][self::hash($p)]);
	}

	private function getFarm(Block $block): ?array {
		if ($this->existFarm($block)) {
			return $this->farm[($world = ($p = $block->getPos())->getWorld())->getFolderName()][self::chunkHash($world->getChunkAtPosition($p))][self::hash($p)];
		}
		return null;
	}

	private function addFarm(Position $pos, Block $block): void {
		if (!isset($this->data[$block->getId()])) {
			return;
		}
		$this->removeFarm($pos);
		$hash = self::hash($pos);
		$chunk_hash = self::chunkHash($pos->getWorld()->getChunkAtPosition($pos));
		$this->queue[$hash] = [$block->getId(),
		                       time()
		];
		$this->farm[$pos->getWorld()->getFolderName()][$chunk_hash][$hash]
			= [$block->getId(),
			   time()
		];
	}

	private function setBlock(Position $position, array $data, bool $force = false): void {
		if (!$force && !$position->getWorld()->isChunkLoaded($position->getFloorX(), $position->getFloorY())) {
			if (isset($this->queue[$hash = self::hash($position)])) {
				unset($this->queue[$hash]);
			}
		}
		$block = clone $position->getWorld()->getBlock($position);
		//var_dump($position->__toString(), $block->getId(), $block->getMeta());
		if (!$block->getId()) {
			return;
		}
		if (!isset($this->data[$block->getId()]) || $block->getId() !== $data[0]) {
			$this->removeFarm($position);
			return;
		}
		$growing = time() - $data[1];
		$need = $this->data[$data[0]];
		if ($need <= $growing) {
			$block->readStateFromData(0, 7);
			$position->getWorld()->setBlock($position, $block);

			if ($block instanceof Stem) {
				/** @var Block $grow */
				$grow = self::invoke($block, "getPlant");
				$find = false;
				foreach (Facing::HORIZONTAL as $side) {
					if ($block->getSide($side)->isSameType($grow)) {
						$find = true;
						break;
					}
				}
				if (!isset($data[2])) {
					$data[] = time() + mt_rand(7 * 60, 17 * 60);
					$this->setFarmData($block->getPos(), $data);
				}
				if ($data[2] <= time() && !$find) {
					$f = Facing::HORIZONTAL;
					shuffle($f);
					foreach ($f as $_ => $s) {
						$side = $block->getSide($s);
						$d = $side->getSide(Facing::DOWN);
						if ($side->getId() === BlockLegacyIds::AIR and ($d->getId() === BlockLegacyIds::FARMLAND or $d->getId() === BlockLegacyIds::GRASS or $d->getId()
								=== BlockLegacyIds::DIRT)
						) {
							$block->getPos()->getWorld()->setBlock($side->getPos(), $grow);
							if (isset($this->queue[$hash = self::hash($position)])) {
								unset($this->queue[$hash]);
							}
							break;
						}
					}
				} elseif (isset($this->queue[$hash = self::hash($position)]) && $find) {
					unset($this->queue[$hash]);
				} else {
					$this->queue[$hash] = $data;
				}
			} elseif (isset($this->queue[$hash = self::hash($position)])) {
				unset($this->queue[$hash]);
			}
		} else {
			$block->readStateFromData(0, (int)($growing / $need * 7));
			$position->getWorld()->setBlock($position, $block);

			if (!isset($this->queue[$hash = self::hash($position)])) {
				$this->queue[$hash] = $data;
			}
		}
	}

}
