<?php

declare(strict_types=1);

namespace solo\convertworld;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\level\LevelInitEvent;
use pocketmine\event\level\LevelLoadEvent;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\io\LevelProviderManager;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\Task;
use pocketmine\utils\Config;

class ConvertWorld extends PluginBase{

	public static $prefix = "§b§l[ConvertWorld] §r§7";

	public static $LEVEL_PATH;
	public static $CHUNK_COPY_PER_TICK = 200;
	public static $JE_to_BE_map;
	public static $BE_to_JE_map;

	protected function onEnable(){
		self::$LEVEL_PATH = $this->getServer()->getDataPath() . "worlds/";

		self::$JE_to_BE_map = (new Config($this->getDataFolder() . "JE_to_BE_map.yml", Config::YAML))->getAll();
		self::$BE_to_JE_map = (new Config($this->getDataFolder() . "BE_to_JE_map.yml", Config::YAML))->getAll();

		new ConvertService($this);

		$this->getServer()->getCommandMap()->register("convertworld", new class($this) extends Command{
			private $plugin;
			private $temporalSender;

			public function __construct(ConvertWorld $plugin){
				parent::__construct("convertworld", "convert world's format to OO - anvil, mcregion, pmanvil, leveldb", "/convertworld <source> <target> <format> [<source edition> <target edition>]");

				$this->plugin = $plugin;
			}

			public function message(string $message){
				$this->temporalSender->sendMessage(ConvertWorld::$prefix . $message);
			}

			public function usage(){
				$this->temporalSender->sendMessage(ConvertWorld::$prefix . $this->getUsage() . " - " . $this->getDescription());
				$this->temporalSender->sendMessage(ConvertWorld::$prefix . "If you want to convert block id/data, <source edition> and <target edition> would be one of this: \"BE JE\" or \"JE BE\"");
			}

			public function execute(CommandSender $sender, string $label, array $args) : bool{
				$this->temporalSender = $sender;

				$this->_execute($sender, $args);

				$this->temporalSender = null;
				return true;
			}

			private function _execute(CommandSender $sender, array $args){
				$source_name = array_shift($args);
				if($source_name === null) return $this->usage();

				$target_name = array_shift($args);
				if($target_name === null) return $this->usage();

				$format = array_shift($args);
				if($format === null) return $this->usage();

				$code_map = [];

				if(!empty($args)){
					$source_edition = array_shift($args);
					$target_edition = array_shift($args);

					if(empty($source_edition) or empty($target_edition)) return $this->usage();

					$source_edition = strtolower($source_edition);
					$target_edition = strtolower($target_edition);

					$je = ["je", "java edition", "java_edition", "javaedition"];
					$be = ["be", "bedrock edition", "bedrock_edition", "bedrockedition", "pe", "pocket edition", "pocket_edition", "pocketedition"];

					if(array_search($source_edition, $je) !== false and array_search($target_edition, $be) !== false){
						$code_map = ConvertWorld::$JE_to_BE_map;
					}else if(array_search($source_edition, $be) !== false and array_search($target_edition, $je) !== false){
						$code_map = ConvertWorld::$BE_to_JE_map;
					}else{
						return $this->usage();
					}
				}

				$source = $this->plugin->getServer()->getLevelByName($source_name);
				if(!$source instanceof Level){
					// try to load level
					$this->message("World \"" . $source_name . "\" seems not loaded, try to load the world...");

					if(!$this->plugin->getServer()->loadLevel($source_name)){
						return $this->message("World \"" . $source_name . "\" is not exist.");
					}
					$source = $this->plugin->getServer()->getLevelByName($source_name);
				}

				$this->message("Starting to convert \"" . $source_name . "\" to \"" . $target_name . "\"...");

				try{
					$this->plugin->convert(
						$source,
						$target_name,
						$format,
						$code_map,
						function(int $x, int $z){
							echo "Convert chunk ... [" . $x . ":" . $z . "]" . "\r";
						},
						function() use($sender){
							$sender->sendMessage(ConvertWorld::$prefix . "Successfully converted.");
						});
				}catch(\InvalidStateException $e){
					$this->message("An error occured while cloning world : " . $e->getMessage());
					$this->message("See console for getting more information.");

					$this->plugin->getServer()->getLogger()->logException($e);
				}
			}
		});
	}

	protected function onDisable(){

	}

	private function hash(int $x, int $z){
		return $x . ":" . $z;
	}

	public function floodFill(callable $callback, int $depth = 10, int $x = 1, int $z = 1, \stdClass $storage = null) : \Generator{
		$recursiveCalled = true;
		$hash = $this->hash($x, $z);

		if($storage === null){
			$storage = new \stdClass();
			$storage->depth = [];
			$storage->checked = [];
			$storage->next = [];

			$storage->depth[$hash] = $depth;

			$recursiveCalled = false;
		}

		if(isset($storage->checked[$hash])) return;
		$storage->checked[$hash] = true;

		$w = $callback($x, $z) === true ? 0 : -1;
		$depth += $w;

		if($depth > 0) foreach([
			[$x + 1, $z],
			[$x - 1, $z],
			[$x, $z + 1],
			[$x, $z - 1]
		] as $near){
			$_hash = $this->hash(...$near);
			$storage->depth[$_hash] = $depth;
			$storage->next[] = $near;
		}

		if(!$recursiveCalled){
			$count = 0;
			$count_per_tick = 0;

			while(!empty($storage->next)){

				$next = array_shift($storage->next);
				$_hash = $this->hash(...$next);

				if($next !== null){
					list($_x, $_z) = $next;

					$this->floodFill($callback, $storage->depth[$_hash], $_x, $_z, $storage)->next();

					if(++$count_per_tick >= self::$CHUNK_COPY_PER_TICK){
						$count += $count_per_tick;
						$count_per_tick = 0;

						yield;
					}
				}
			}
		}
	}

	public function convert(Level $source, string $target_name, string $format = "pmanvil", array $code_map = [], callable $floodFill_callback = null, callable $finish_callback = null){
		$server = $this->getServer();

		$source_path = self::$LEVEL_PATH . $source->getFolderName() . "/";
		$target_path = self::$LEVEL_PATH . $target_name . "/";

		// make target directory
		@mkdir($target_path);

		// clone level.dat
		//copy($source_path . "level.dat", $target_path . "level.dat");

		// generate target level
		if($server->isLevelGenerated($target_name)){
			throw new \InvalidStateException("target world \"" . $target_name . "\" is already generated");
		}

		$providerClass = LevelProviderManager::getProviderByName($format);
		if($providerClass === null){
			throw new \InvalidStateException("Given provider has not been registered");
		}

		$options = [
			"preset" => ""
		];

		$providerClass::generate(
			$target_path,
			$target_name,
			$source->getSeed(),
			$source->getProvider()->getLevelData()->getGenerator(),
			$options
		);

		$_levels = (new \ReflectionObject($server))->getProperty("levels");
		$_levels->setAccessible(true);
		$levels = $_levels->getValue($server);

		$target = new Level($server, $target_name, new $providerClass($target_path));

		$levels[$target->getId()] = $target;
		$_levels->setValue($server, $levels);

		$target->setTickRate($server->getProperty("level-settings.base-tick-rate", 1));

		(new LevelInitEvent($target))->call();
		(new LevelLoadEvent($target))->call();

		// convert start ...

		$convertProcess = $this->floodFill(function(int $chunkX, int $chunkZ) use($source, $target, $code_map, $floodFill_callback) : bool{
			if(!$source->loadChunk($chunkX, $chunkZ, false)) return false;

			$source_chunk = $source->getChunk($chunkX, $chunkZ);
			$target_chunk = Chunk::fastDeserialize($source_chunk->fastSerialize());

			$maxY = $target_chunk->getMaxY();

			if(!empty($code_map)) for($y = 0; $y < $maxY; $y++){
				for($z = 0; $z < 16; $z++){
					for($x = 0; $x < 16; $x++){
						$blockId = $target_chunk->getBlockId($x, $y, $z);
						$blockData = $target_chunk->getBlockData($x, $y, $z);

						if(isset($code_map[$blockId . ":" . $blockData])){
							$id_data = explode(":", $code_map[$blockId . ":" . $blockData]);
							$target_chunk->setBlock($x, $y, $z, intval($id_data[0]), intval($id_data[1] ?? 0));
						}else if(isset($code_map[$blockId])){
							$target_chunk->setBlock($x, $y, $z, $code_map[$blockId], $blockData);
						}
					}
				}
			}

			$target->setChunk($chunkX, $chunkZ, $target_chunk);

			if($floodFill_callback !== null){
				$floodFill_callback($chunkX, $chunkZ);
			}

			return true;
		});

		ConvertService::$instance->addConvertProcess($convertProcess, $finish_callback);
	}
}

class ConvertService{

	public static $instance;

	private $plugin;
	private $processes = [];

	public function __construct(ConvertWorld $plugin){
		self::$instance = $this;

		$this->plugin = $plugin;

		$this->plugin->getScheduler()->scheduleRepeatingTask(new class($this) extends Task{
			private $service;

			public function __construct(ConvertService $service){
				$this->service = $service;
			}

			public function onRun(int $currentTick) : void{
				$this->service->doTick($currentTick);
			}
		}, 1);
	}

	public function addConvertProcess(\Generator $convertProcess, callable $finish_callback = null){
		$this->processes[] = [$convertProcess, $finish_callback];
	}

	public function doTick(int $currentTick){
		if(empty($this->processes)) return;

		foreach($this->processes as $id => list($process, $finish_callback)){
			foreach($process as $empty){
				$this->doGarbageCollection();
			}

			if(!$process->valid()){
				if($finish_callback !== null) $finish_callback();

				unset($this->processes[$id]);
				continue;
			}
		}
	}

	public function doGarbageCollection(){
		foreach($this->plugin->getServer()->getLevels() as $level){
			$level->doChunkGarbageCollection();
			$level->unloadChunks(true);
			$level->clearCache(true);
		}

		$this->plugin->getServer()->getMemoryManager()->triggerGarbageCollector();
	}
}