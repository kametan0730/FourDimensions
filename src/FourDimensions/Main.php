<?php

namespace FourDimensions;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\UnknownBlock;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\level\ChunkLoadEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\SubChunk;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\NetworkBinaryStream;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\plugin\PluginBase;

use pocketmine\plugin\PluginLogger;
use pocketmine\Server;
use pocketmine\Player;

use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

	/* @var bool */
	public $isVirtualChunkEnabled;

	/* @var int */
	public $virtualChunkLeftX;
	public $virtualChunkRightX;
	public $virtualChunkLeftZ;
	public $virtualChunkRightZ;
	public $virtualChunkMaxHeight;

	/* @var string */
	public $virtualChunkEnabledLevel;

	/* @var Config */
	private $config;

	/* @var BlockDatabase */
	private $defaultBlockDatabase;

	/* @var BlockDatabase[] */
	private $blockDatabases = []; // land_identifierとBlockDatabaseの対応

	/* @var string[] */
	private $loadingLandIdentifier = []; // usernameとland_identifierの対応

	/* @var BatchPacketFilter */
	private $packetFilter;

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this,$this);
		@mkdir($this->getDataFolder() . "vchunk_data");
		$this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML,
			[
				"is_virtual_chunk_enabled" => false,
				"virtual_chunk_left_x" => 0,
				"virtual_chunk_right_x" => 0,
				"virtual_chunk_left_z" => 0,
				"virtual_chunk_right_z" => 0,
				"virtual_chunk_max_height" => 64,
				"virtual_chunk_enabled_level" => "world"
			]
		);
		$this->isVirtualChunkEnabled = $this->config->get("is_virtual_chunk_enabled");
		$this->virtualChunkLeftX = $this->config->get("virtual_chunk_left_x");
		$this->virtualChunkRightX = $this->config->get("virtual_chunk_right_x");
		$this->virtualChunkLeftZ = $this->config->get("virtual_chunk_left_z");
		$this->virtualChunkRightZ = $this->config->get("virtual_chunk_right_z");
		$this->virtualChunkMaxHeight = $this->config->get("virtual_chunk_max_height");
		$this->virtualChunkMaxHeight = $this->config->get("virtual_chunk_max_height");
		$this->virtualChunkEnabledLevel = $this->config->get("virtual_chunk_enabled_level");

		$this->defaultBlockDatabase = new BlockDatabase($this->getDataFolder() , "default");
		BlockDatabase::setDefaultDataBasePath($this->defaultBlockDatabase->getDatabasePath());

		$this->packetFilter = new BatchPacketFilter();
	}

	public function onDataPacetReceive(DataPacketReceiveEvent $event){
		$packet = $event->getPacket();
		$player = $event->getPlayer();
		if(!$this->isPlayerInVLandEnabledLevel($player)) return;
		if($packet instanceof InventoryTransactionPacket){
			switch($packet->transactionType){
				case InventoryTransactionPacket::TYPE_USE_ITEM:
					switch($packet->trData->actionType){
						case InventoryTransactionPacket::USE_ITEM_ACTION_CLICK_BLOCK: // ブロックの設置
							$item = $player->getInventory()->getItemInHand();
							if(!BlockFactory::isRegistered($item->getId())) return; // ItemがBlockでもあるかどうか
							$blockPos = new Vector3($packet->trData->x, $packet->trData->y, $packet->trData->z);
							switch($packet->trData->face){
								case 0:
									$blockPos->y--;
									break;
								case 1:
									$blockPos->y++;
									break;
								case 2:
									$blockPos->z--;
									break;
								case 3:
									$blockPos->z++;
									break;
								case 4:
									$blockPos->x--;
									break;
								case 5:
									$blockPos->x++;
									break;
							}

							if(!$this->isPosInVLand($blockPos->x, $blockPos->z)) return;
							$event->setCancelled();
							$pk = new UpdateBlockPacket();
							$pk->x = $blockPos->x;
							$pk->y = $blockPos->y;
							$pk->z = $blockPos->z;
							$pk->blockRuntimeId = BlockFactory::toStaticRuntimeId($item->getId(), $item->getDamage());
							$pk->flags = UpdateBlockPacket::FLAG_NONE;
							$player->sendDataPacket($pk);
							$this->blockDatabases[$this->loadingLandIdentifier[strtolower($player->getName())]]->setBlock($blockPos->x >> 4, $blockPos->z >> 4, floor($blockPos->y / 16), $blockPos->x % 16, $blockPos->y % 16,$blockPos->z % 16, $item->getId(), $item->getDamage());
							break;
						case InventoryTransactionPacket::USE_ITEM_ACTION_BREAK_BLOCK: // ブロックの破壊
							$blockPos = new Vector3($packet->trData->x, $packet->trData->y, $packet->trData->z);
							if(!$this->isPosInVLand($blockPos->x, $blockPos->z)) return;
							$event->setCancelled();
							$this->blockDatabases[$this->loadingLandIdentifier[strtolower($player->getName())]]->unsetBlock($blockPos->x >> 4, $blockPos->z >> 4, floor($blockPos->y / 16), $blockPos->x % 16, $blockPos->y % 16,$blockPos->z % 16);
							$pk = new LevelEventPacket;
							$pk->evid = LevelEventPacket::EVENT_PARTICLE_DESTROY;
							$pk->position = $blockPos->add(0.5, 0.5, 0.5);
							$pk->data = $packet->trData->blockRuntimeId;
							$player->sendDataPacket($pk);
							break;
					}
					break;
			}
		}
	}

	public function isPosInVLand($x, $z) : bool { // PosがVLandの中にあるか
		$chunkX = $x >> 4;
		$chunkZ = $z >> 4;
		return ($this->virtualChunkLeftX <= $chunkX and $chunkX <= $this->virtualChunkRightX and $this->virtualChunkLeftZ <= $chunkZ and $chunkZ <= $this->virtualChunkRightZ);
	}

	public function onDataPacketSend(DataPacketSendEvent $event){
		if(!$this->isVirtualChunkEnabled or !$this->isPlayerInVLandEnabledLevel($event->getPlayer())) return;
		$packet = $event->getPacket();
		$player = $event->getPlayer();
		if($packet instanceof BatchPacket){
			$packet->decode();
			if(strlen($packet->payload) === 0) return;
			foreach($packet->getPackets() as $containedPacketBuffer){
				$containedPacketPid = ord($containedPacketBuffer{0});
				if($containedPacketPid === LevelChunkPacket::NETWORK_ID){
					$levelChunkPacketStream = new BinaryStream($containedPacketBuffer);
					$levelChunkPacketStream->getByte(); // pid分の1byte
					$chunkX = $levelChunkPacketStream->getVarInt(); // LevelChunkPacketをまるごとデコードすると負担がかかりそうだから必要な部分だけ
					$chunkZ = $levelChunkPacketStream->getVarInt();
					if(!($this->virtualChunkLeftX <= $chunkX and $chunkX <= $this->virtualChunkRightX and $this->virtualChunkLeftZ <= $chunkZ and $chunkZ <= $this->virtualChunkRightZ)) return;
					if($this->packetFilter->isAccept($packet)) return;
					$event->setCancelled();
					$vChunkPayload = $this->buildVChunk($chunkX, $chunkZ, strtolower($player->getName()));
					$newLevelChunkPacket = LevelChunkPacket::withoutCache($chunkX, $chunkZ, $this->virtualChunkMaxHeight / 16, $vChunkPayload);
					$newBatchPacket = new BatchPacket();
					$newBatchPacket->addPacket($newLevelChunkPacket);
					$newBatchPacket->setCompressionLevel($this->getServer()->networkCompressionLevel);
					$newBatchPacket->encode();
					$this->packetFilter->addAcceptPacket($newBatchPacket); // 通過していいよー
					$player->sendDataPacket($newBatchPacket);
				}
			}
		}
	}

	public function buildVChunk($chunkX, $chunkZ, $dataIdentifier){
		$blockDataBase = $this->blockDatabases[$dataIdentifier];
		$subChunkCount = $this->virtualChunkMaxHeight / 16;

		/* @var SubChunk[] $subChunks */
		$subChunks = [];

		for($i=0;$i<$subChunkCount;$i++){
			$subChunks[] = new SubChunk();
		}

		foreach($blockDataBase->getAllChunkBlocks($chunkX, $chunkZ) as $block){
			$subChunkY = $block["subChunkHeight"];
			$blockX = $block["blockX"];
			$blockY = $block["blockY"];
			$blockZ = $block["blockZ"];
			$id = $block["id"];
			$meta = $block["meta"];
			$subChunks[$subChunkY]->setBlock($blockX, $blockY, $blockZ, $id, $meta);
		}
		$chunk = new Chunk($chunkX, $chunkZ, $subChunks);
		$payload = $chunk->networkSerialize();
		return $payload;
	}

	public function onPlayerLogin(PlayerLoginEvent $event){
		$player = $event->getPlayer();
		$land_identifier = strtolower($player->getName());
		$this->loadingLandIdentifier[strtolower($player->getName())] = $land_identifier; // あとあとの拡張性の為、land_identifierは直接はusernameと紐ずけておかない。
		$blockDatabase = new BlockDatabase($this->getDataFolder() . "vchunk_data" . DIRECTORY_SEPARATOR, $land_identifier);
		$this->blockDatabases[$land_identifier] = $blockDatabase; // usernameからは、username->loadingLandIdentifier->landIdentifier->BlockDatabaseという順序でアクセスできる。
	}

	public function isPlayerInVLandEnabledLevel(Player $player) : bool{ // PlayerがVLandの有効なワールドにいるか
		return $player->getLevel()->getName() === $this->virtualChunkEnabledLevel;
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("This command is for only player");
			return true;
		}
		if(!$this->isPlayerInVLandEnabledLevel($sender)){
			$sender->sendMessage("This command can only be used in ".$this->virtualChunkEnabledLevel);
			return true;
		}
		switch(strtolower($command->getName())){

			case "vland":
				if(!isset($args[0])) return false;
				switch(strtolower($args[0])){
					case "info":
						$sender->sendMessage("VLand is " . (($this->isVirtualChunkEnabled) ? "enabled" : "disabled" ) ." now");
						$sender->sendMessage("VLand area chunk : (" . $this->virtualChunkLeftX . "," . $this->virtualChunkLeftZ . ") ~ (" . $this->virtualChunkRightX.",". $this->virtualChunkRightZ . ")");
						$sender->sendMessage("VLand enabled level : ".$this->virtualChunkEnabledLevel);
						if($sender->chunk !== null){
							$sender->sendMessage("Your chunk : (".$sender->chunk->getX().",".$sender->chunk->getZ().")");
						}
						return true;
					case "default":
						if(!isset($args[1])) return false;
						switch(strtolower($args[1])){
							case "scan":
								$sender->sendMessage("Start world scan");
								$this->defaultBlockDatabase->reset();
								$level = $sender->getLevel();
								for($chunkX=$this->virtualChunkLeftX;$chunkX<=$this->virtualChunkRightX;$chunkX++){
									for($chunkZ=$this->virtualChunkLeftZ;$chunkZ<=$this->virtualChunkRightZ;$chunkZ++){
										$chunk = $level->getChunk($chunkX, $chunkZ);
										if($chunk->isGenerated()){
											$subChunks = $chunk->getSubChunks();
											foreach($subChunks as $subChunkY => $subChunk){
												if($subChunk->isEmpty()) continue;
												for($x=0;$x<16;$x++){
													for($y=0;$y<16;$y++){
														for($z=0;$z<16;$z++){
															$blockId = $subChunk->getBlockId($x, $y, $z);
															if($blockId !== Block::AIR){
																$blockData = $subChunk->getBlockData($x, $y, $z);
																$this->defaultBlockDatabase->setBlock($chunkX, $chunkZ, $subChunkY, $x, $y, $z, $blockId, $blockData);
															}
														}
													}
												}
											}
										}
									}
								}
								$sender->sendMessage("Finished world scan");
								return true;
						}
						break;
				}
				return false;
			default:
				return false;
		}
	}
}
