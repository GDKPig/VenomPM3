<?php
/**
 * Created by PhpStorm.
 * User: jkorn2324
 * Date: 2019-05-03
 * Time: 19:33
 */

namespace practice\game\inventory\menus\inventories;


use pocketmine\block\Block;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\WindowTypes;
use pocketmine\Player;
use pocketmine\tile\Tile;
use practice\game\inventory\menus\BaseMenu;
use practice\game\inventory\menus\data\PracHolderData;

class SingleChestInv extends PracBaseInv
{
    const CHEST_SIZE = 27;

    public function __construct(BaseMenu $menu, array $items = []) {
        parent::__construct($menu, $items, self::CHEST_SIZE, null);
    }

    public function getName() : string {
        return 'Practice Chest';
    }

    /**
     * Returns the Minecraft PE inventory type used to show the inventory window to clients.
     * @return int
     */
    public function getNetworkType(): int {
        return WindowTypes::CONTAINER;
    }

    public function getTEId(): string {
        return Tile::CHEST;
    }

    function sendPrivateInv(Player $player, PracHolderData $data): void {

        $block = $this->getBlock()->setComponents($data->getPos()->x, $data->getPos()->y, $data->getPos()->z);
        $player->getLevel()->sendBlocks([$player], [$block]);
        $tag = new CompoundTag();
        if(!is_null($data->getCustomName())) {
            $tag->setString('CustomName', $data->getCustomName());
        }

        $this->sendTileEntity($player, $block, $tag);

        $this->onInventorySend($player);
    }

    function sendPublicInv(Player $player, PracHolderData $data): void {
        $player->getLevel()->sendBlocks([$player], [$data->getPos()]);
    }

    private function getBlock() : Block {
        return Block::get(Block::CHEST);
    }
}