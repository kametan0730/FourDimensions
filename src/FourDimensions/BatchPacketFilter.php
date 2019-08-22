<?php


namespace FourDimensions;


use pocketmine\network\mcpe\protocol\BatchPacket;

class BatchPacketFilter{

	/* @var string[] */
	public $acceptHashList = [];

	public function addAcceptPacket(BatchPacket $batchPacket){
		$this->acceptHashList[] = spl_object_hash($batchPacket);
	}

	public function isAccept(BatchPacket $batchPacket){
		$hash = spl_object_hash($batchPacket);
		$key = array_search($hash, $this->acceptHashList);
		if($key === false) {
			return false;
		}
		unset($this->acceptHashList[$key]);
		return true;
	}
}