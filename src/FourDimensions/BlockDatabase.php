<?php


namespace FourDimensions;


use pocketmine\plugin\PluginBase;

class BlockDatabase{

	/* @var string */
	private static $defaultDatabasePath = "";

	/* @var string */
	private $databasePath;

	/* @var /SQLite3 */
	private $database;

	public function __construct(string $databaseDirectory, string $identify){
		$this->databasePath = $databaseDirectory . $identify . ".db";

		if(file_exists($this->databasePath)){
			$this->database = new \SQLite3($this->databasePath, SQLITE3_OPEN_READWRITE);
		}elseif(!file_exists($this->databasePath) and self::$defaultDatabasePath !== ""){
			copy(self::$defaultDatabasePath, $this->databasePath);
			$this->database = new \SQLite3($this->databasePath, SQLITE3_OPEN_READWRITE);
		}else{
			$this->database = new \SQLite3($this->databasePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		}

		$this->database->query("CREATE TABLE IF NOT EXISTS blocks (chunkX INTEGER, chunkZ INTEGER, subChunkHeight INTEGER, blockX INTEGER, blockY INTEGER, blockZ INTEGER, id INTEGER, meta INTEGER, PRIMARY KEY (chunkX, chunkZ, subChunkHeight, blockX, blockY, blockZ))");
	}

	public function setBlock(int $chunkX, int $chunkZ, int $subChunkHeight, int $blockX, int $blockY, int $blockZ, int $id, int $meta){
		$this->database->query("INSERT OR REPLACE INTO blocks values ($chunkX, $chunkZ, $subChunkHeight, $blockX, $blockY, $blockZ, $id, $meta) ");
	}

	public function unsetBlock(int $chunkX, int $chunkZ, int $subChunkHeight, int $blockX, int $blockY, int $blockZ){
		$this->database->query("DELETE FROM blocks WHERE chunkX = $chunkX AND chunkZ = $chunkZ AND subChunkHeight = $subChunkHeight AND blockX = $blockX AND blockY = $blockY AND blockZ = $blockZ");
	}

	public function getAllChunkBlocks(int $chunkX, int $chunkZ){
		$results = $this->database->query("SELECT subChunkHeight, blockX, blockY, blockZ, id, meta FROM blocks WHERE chunkX = $chunkX AND chunkZ = $chunkZ");
		while(($result =  $results->fetchArray()) !== false){
			yield $result;
		}
	}

	public function reset(){
		$this->database->query("DELETE FROM blocks");
	}

	public function getDatabasePath() : string {
		return $this->databasePath;
	}

	public static function setDefaultDataBasePath(string $path){
		self::$defaultDatabasePath = $path;
	}
}